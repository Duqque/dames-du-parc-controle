/*
 * Lecteur de codes-barres Code 128 (billets du site : 12 chiffres, jeu C), écrit pour l'application de contrôle.
 *
 * Pourquoi un lecteur dédié : sur iPhone, Safari n'a pas de lecteur natif et le lecteur Code 128 de ZXing exige une très large marge
 * blanche autour du code-barres ; il échoue dès que le fond voisin est sombre (bord du billet, écran, main). Celui-ci :
 *  - analyse plusieurs lignes (et colonnes, pour un code-barres vertical) avec un seuil local, insensible au fond ;
 *  - mesure les largeurs de barres, les compare aux 107 motifs de la norme, vérifie la somme de contrôle ;
 *  - n'accepte un résultat que s'il est lu à l'identique sur au moins deux lignes (pas de lecture parasite).
 *
 * window.DDPCode128.decode(imageData) → texte ou null
 */
(function () {
  "use strict";
  const PATTERNS = [
    "212222", "222122", "222221", "121223", "121322", "131222", "122213", "122312", "132212", "221213",
    "221312", "231212", "112232", "122132", "122231", "113222", "123122", "123221", "223211", "221132",
    "221231", "213212", "223112", "312131", "311222", "321122", "321221", "312212", "322112", "322211",
    "212123", "212321", "232121", "111323", "131123", "131321", "112313", "132113", "132311", "211313",
    "231113", "231311", "112133", "112331", "132131", "113123", "113321", "133121", "313121", "211331",
    "231131", "213113", "213311", "213131", "311123", "311321", "331121", "312113", "312311", "332111",
    "314111", "221411", "431111", "111224", "111422", "121124", "121421", "141122", "141221", "112214",
    "112412", "122114", "122411", "142112", "142211", "241211", "221114", "413111", "241112", "134111",
    "111242", "121142", "121241", "114212", "124112", "124211", "411212", "421112", "421211", "212141",
    "214121", "412121", "111143", "111341", "131141", "114113", "114311", "411113", "411311", "113141",
    "114131", "311141", "411131", "211412", "211214", "211232",
  ].map((p) => [...p].map(Number));
  const STOP = [2, 3, 3, 1, 1, 1, 2];
  const MAX_VARIANCE = 0.42; // écart moyen toléré par élément (en modules)

  /** Écart moyen entre des largeurs mesurées et un motif (en modules), après normalisation sur le total du motif. */
  function variance(runs, pattern, total) {
    let sum = 0;
    for (let i = 0; i < pattern.length; i++) sum += runs[i];
    const unit = sum / total;
    let dev = 0;
    for (let i = 0; i < pattern.length; i++) dev += Math.abs(runs[i] / unit - pattern[i]);
    return dev / pattern.length;
  }

  function matchSymbol(runs) {
    let best = -1;
    let bestVar = MAX_VARIANCE;
    for (let c = 0; c < PATTERNS.length; c++) {
      const v = variance(runs, PATTERNS[c], 11);
      if (v < bestVar) {
        bestVar = v;
        best = c;
      }
    }
    return best;
  }

  /** Décode une suite de largeurs (barre, espace, barre…) à partir de l'indice i (une barre). */
  function decodeFrom(runs, i) {
    const start = matchSymbol(runs.slice(i, i + 6));
    if (start < 103 || start > 105) return null;
    // Zone blanche avant le code : au moins 2 modules.
    const unit = runs.slice(i, i + 6).reduce((a, b) => a + b, 0) / 11;
    if (i > 0 && runs[i - 1] < unit * 2) return null;
    const codes = [start];
    let p = i + 6;
    for (let n = 0; n < 60; n++) {
      if (p + 7 <= runs.length && variance(runs.slice(p, p + 7), STOP, 13) < MAX_VARIANCE) {
        return finish(codes);
      }
      if (p + 6 > runs.length) return null;
      const c = matchSymbol(runs.slice(p, p + 6));
      if (c < 0 || c >= 103) return null;
      codes.push(c);
      p += 6;
    }
    return null;
  }

  /** Somme de contrôle puis traduction (jeux A, B, C, avec changements de jeu). */
  function finish(codes) {
    if (codes.length < 3) return null;
    const check = codes[codes.length - 1];
    let sum = codes[0];
    for (let k = 1; k < codes.length - 1; k++) sum += codes[k] * k;
    if (sum % 103 !== check) return null;
    let set = codes[0] === 105 ? "C" : codes[0] === 104 ? "B" : "A";
    let out = "";
    for (let k = 1; k < codes.length - 1; k++) {
      const c = codes[k];
      if (set === "C") {
        if (c < 100) out += String(c).padStart(2, "0");
        else if (c === 100) set = "B";
        else if (c === 101) set = "A";
        else return null;
      } else {
        if (c === 99) set = "C";
        else if (c === 100 && set === "A") set = "B";
        else if (c === 101 && set === "B") set = "A";
        else if (c < 96) out += set === "B" ? String.fromCharCode(c + 32) : String.fromCharCode(c < 64 ? c + 32 : c - 64);
        else return null;
      }
    }
    return out.length >= 4 ? out : null;
  }

  /** Largeurs des zones sombres / claires d'une ligne de luminance, avec un seuil local (insensible au fond et à l'éclairage). */
  function runsOf(line) {
    const n = line.length;
    // Lissage léger (bruit du capteur).
    const s = new Float32Array(n);
    for (let x = 0; x < n; x++) s[x] = (line[Math.max(0, x - 1)] + 2 * line[x] + line[Math.min(n - 1, x + 1)]) / 4;
    const win = Math.max(12, Math.round(n / 10));
    // Minimum et maximum glissants (fenêtre ±win) : seuil = milieu, seulement là où le contraste est suffisant.
    const bits = new Uint8Array(n);
    for (let x = 0; x < n; x++) {
      let lo = 255;
      let hi = 0;
      const a = Math.max(0, x - win);
      const b = Math.min(n - 1, x + win);
      for (let k = a; k <= b; k += 2) {
        if (s[k] < lo) lo = s[k];
        if (s[k] > hi) hi = s[k];
      }
      bits[x] = hi - lo < 40 ? (s[x] < 100 ? 1 : 0) : s[x] < (lo + hi) / 2 ? 1 : 0;
    }
    const runs = [];
    const firstDark = [];
    let cur = bits[0];
    let len = 0;
    for (let x = 0; x < n; x++) {
      if (bits[x] === cur) len++;
      else {
        runs.push(len);
        firstDark.push(cur === 1);
        cur = bits[x];
        len = 1;
      }
    }
    runs.push(len);
    firstDark.push(cur === 1);
    return { runs, dark: firstDark };
  }

  function decodeLine(line) {
    const { runs, dark } = runsOf(line);
    for (let i = 1; i < runs.length - 20; i++) {
      if (!dark[i]) continue;
      const t = decodeFrom(runs, i);
      if (t) return t;
    }
    // Lecture dans l'autre sens (code-barres tenu à l'envers).
    const rr = runs.slice().reverse();
    const rd = dark.slice().reverse();
    for (let i = 1; i < rr.length - 20; i++) {
      if (!rd[i]) continue;
      const t = decodeFrom(rr, i);
      if (t) return t;
    }
    return null;
  }

  /** Analyse une image (ImageData) : lignes horizontales puis colonnes ; résultat retenu s'il est lu au moins deux fois. */
  function decode(img, lines = 21) {
    const { width: w, height: h, data } = img;
    const lum = new Uint8ClampedArray(w * h);
    for (let i = 0, j = 0; j < lum.length; i += 4, j++) lum[j] = (data[i] * 77 + data[i + 1] * 150 + data[i + 2] * 29) >> 8;
    const votes = new Map();
    const vote = (t) => {
      if (!t) return null;
      const n = (votes.get(t) || 0) + 1;
      votes.set(t, n);
      return n >= 2 ? t : null;
    };
    for (let k = 1; k <= lines; k++) {
      const y = Math.round((h * k) / (lines + 1));
      const r = vote(decodeLine(lum.subarray(y * w, y * w + w)));
      if (r) return r;
    }
    const col = new Uint8ClampedArray(h);
    for (let k = 1; k <= lines; k++) {
      const x = Math.round((w * k) / (lines + 1));
      for (let y = 0; y < h; y++) col[y] = lum[y * w + x];
      const r = vote(decodeLine(col));
      if (r) return r;
    }
    return null;
  }

  window.DDPCode128 = { decode };
})();
