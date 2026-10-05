"use strict";
const form = document.querySelector("[data-nc-form]");
if (form) {
  let changed = false;
  let sending = false;
  form.addEventListener("input", () => { changed = true; });
  form.addEventListener("submit", (event) => {
    if (sending) { event.preventDefault(); return; }
    sending = true;
    const button = form.querySelector("[data-submit]");
    button.disabled = true;
    button.textContent = "Enregistrement en cours…";
  });
  form.querySelector("[data-cancel]").addEventListener("click", (event) => {
    if (changed && !window.confirm("Annuler et perdre la saisie de ce formulaire ?")) event.preventDefault();
  });
  window.addEventListener("beforeunload", (event) => {
    if (changed && !sending) { event.preventDefault(); event.returnValue = ""; }
  });
  window.addEventListener("pageshow", () => {
    sending = false;
    const button = form.querySelector("[data-submit]");
    button.disabled = false;
    button.textContent = "Enregistrer la non-conformité";
  });
}
