(function () {
  "use strict";
  const s = document.createElement("script");
  s.src = "/assets/vendor/qrcode-generator.js";
  s.onload = () => {
    document.querySelectorAll("[data-qr]").forEach((el) => {
      const q = window.qrcode(0, "M");
      q.addData(el.dataset.qr);
      q.make();
      el.innerHTML = q.createSvgTag({ cellSize: 4, margin: 2, scalable: true });
    });
  };
  document.head.appendChild(s);
  document.addEventListener("click", (ev) => { if (ev.target.closest("[data-print]")) window.print(); });
})();
