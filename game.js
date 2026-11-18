(() => {
  const form = document.querySelector('#puzzle-form');
  if (!form) return;
  const feedback = document.querySelector('#feedback');
  const button = form.querySelector('button[type="submit"]');
  form.addEventListener('submit', async (event) => {
    event.preventDefault();
    feedback.textContent = 'Listening to the lock…';
    feedback.className = 'feedback pending';
    button.disabled = true;
    try {
      const response = await fetch(form.action, {
        method: 'POST',
        body: new FormData(form),
        headers: { Accept: 'application/json', 'X-Requested-With': 'fetch' },
        credentials: 'same-origin'
      });
      const result = await response.json();
      if (!response.ok && response.status !== 200) throw new Error(result.message || 'The room could not hear you.');
      if (result.ok) {
        feedback.textContent = result.message;
        feedback.className = 'feedback success';
        button.disabled = true;
        window.setTimeout(() => { window.location.assign(result.next); }, 900);
      } else {
        feedback.textContent = `${result.message}${result.hint ? ` ${result.hint}` : ''}`;
        feedback.className = 'feedback error';
        button.disabled = false;
        form.elements.answer.focus();
      }
    } catch (error) {
      feedback.textContent = error instanceof Error ? error.message : 'Something went wrong. Please try again.';
      feedback.className = 'feedback error';
      button.disabled = false;
    }
  });
})();
