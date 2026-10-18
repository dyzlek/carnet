// Thème choisi manuellement (sinon : celui du système). Chargé avant l'affichage pour éviter un flash.
try { const t = localStorage.getItem('theme'); if (t) document.documentElement.dataset.theme = t; } catch (e) { /* stockage indisponible */ }
