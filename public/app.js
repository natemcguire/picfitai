const genBtn = document.getElementById('genBtn');
const standingInput = document.getElementById('standingInput');
const outfitInput = document.getElementById('outfitInput');
const emailInput = document.getElementById('emailInput');
const resultSection = document.getElementById('resultSection');
const result = document.getElementById('result');

function toBase64(file) {
  return new Promise((resolve, reject) => {
    const r = new FileReader();
    r.onload = () => resolve(r.result.split(',')[1]);
    r.onerror = reject;
    r.readAsDataURL(file);
  });
}

genBtn.addEventListener('click', async () => {
  const email = emailInput.value.trim();
  if (!email) { alert('Enter your email.'); return; }
  if (!outfitInput.files[0] || standingInput.files.length === 0) {
    alert('Upload at least one standing photo and one outfit photo.');
    return;
  }

  genBtn.disabled = true; genBtn.textContent = 'Generating...';
  try {
    const form = new FormData();
    form.append('email', email);
    Array.from(standingInput.files).slice(0, 5).forEach((f, i) => form.append(`standing_${i}`, f));
    form.append('outfit', outfitInput.files[0]);

    const resp = await fetch('../backend/fit.php', { method: 'POST', body: form });
    if (resp.status === 402) {
      // No credits. Backend returns a helper URL that creates a session.
      const { checkout_url } = await resp.json();
      // Fetch session then redirect to session URL
      const s = await fetch(checkout_url, { method: 'GET' });
      const j = await s.json();
      if (j && j.url) { window.location.href = j.url; return; }
      throw new Error('Unable to start checkout');
    }
    if (!resp.ok) {
      const txt = await resp.text();
      throw new Error(txt || 'Generation failed');
    }
    // Expect image/jpeg blob (stub now returns placeholder)
    const blob = await resp.blob();
    const url = URL.createObjectURL(blob);
    result.innerHTML = `<img src="${url}" class="w-full max-w-sm border-2 border-black"/>`;
    resultSection.classList.remove('hidden');
  } catch (e) {
    alert(e.message || 'Error');
  } finally {
    genBtn.disabled = false; genBtn.textContent = 'Generate my fit';
  }
});


