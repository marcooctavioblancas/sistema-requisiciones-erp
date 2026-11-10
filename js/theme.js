// IIFE — runs before render to prevent flash
(function () {
  const t = localStorage.getItem('srTheme') || 'light';
  document.documentElement.setAttribute('data-theme', t);
})();

function toggleTheme() {
  const html = document.documentElement;
  const next = html.getAttribute('data-theme') === 'dark' ? 'light' : 'dark';
  html.setAttribute('data-theme', next);
  localStorage.setItem('srTheme', next);
  const icon = document.getElementById('themeIcon');
  if (icon) icon.className = next === 'dark' ? 'fas fa-sun' : 'fas fa-moon';
}
