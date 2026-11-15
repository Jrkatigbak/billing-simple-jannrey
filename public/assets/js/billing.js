const share = document.querySelector('#share');
share?.addEventListener('click', async () => {
  const url = new URL(share.dataset.link, location.href).href;
  const box = document.querySelector('#sharebox');
  box.hidden = false;
  box.querySelector('input').value = url;
  box.querySelector('a').href = url;
  try {
    await navigator.clipboard.writeText(url);
    box.querySelector('span').textContent = 'Link copied.';
  } catch {
    box.querySelector('input').select();
    box.querySelector('span').textContent = 'Copy the selected link.';
  }
});
const first = document.querySelector('[name="first"]');
const terms = document.querySelector('[name="terms"]');
if (first && terms) {
  const preview = document.querySelector('#term');
  const format = new Intl.DateTimeFormat('en-US', {
    dateStyle: 'long',
    timeZone: 'UTC',
  });
  const update = () => {
    preview.textContent = '';
    if (!first.value || !first.validity.valid || !terms.validity.valid) return;
    const [year, month, day] = first.value.split('-').map(Number);
    const last = new Date(Date.UTC(year, month + terms.valueAsNumber - 1, 0));
    if (last.getUTCFullYear() > 9999) return;
    last.setUTCDate(Math.min(day, last.getUTCDate()));
    preview.textContent = 'Final payment due ' + format.format(last);
  };
  first.addEventListener('input', update);
  terms.addEventListener('input', update);
  update();
}

const proofDialog = document.querySelector('#proof-preview');
if (proofDialog && typeof proofDialog.showModal === 'function') {
  const image = proofDialog.querySelector('img');
  document.addEventListener('click', (event) => {
    const link = event.target.closest('[data-image-proof]');
    if (!link) return;
    if (event.ctrlKey || event.metaKey || event.shiftKey || event.altKey)
      return;
    event.preventDefault();
    proofDialog.querySelector('h2').textContent =
      'Payment ' + link.dataset.payment + ' · Proof of payment';
    image.src = link.href;
    proofDialog.showModal();
  });
  proofDialog.addEventListener('close', () => image.removeAttribute('src'));
}

let pendingSave = Promise.resolve();
document.addEventListener('submit', (event) => {
  const form = event.target;
  const row = form.closest('tr');
  const operation = form.elements.op?.value;
  if (!row || !['payment', 'details'].includes(operation)) return;
  event.preventDefault();
  if (form.getAttribute('aria-busy') === 'true') return;

  const data = new FormData(form);
  const cell = form.closest('td');
  let message = cell.querySelector('[role="status"]');
  if (!message) {
    message = document.createElement('small');
    message.setAttribute('role', 'status');
    cell.append(message);
  }
  message.textContent = 'Saving…';
  form.setAttribute('aria-busy', 'true');
  const controls = [...form.elements].filter((control) => !control.disabled);
  controls.forEach((control) => {
    control.disabled = true;
  });

  pendingSave = pendingSave.then(async () => {
    try {
      const response = await fetch(form.action, { method: 'POST', body: data });
      const page = new DOMParser().parseFromString(
        await response.text(),
        'text/html',
      );
      const error = page.querySelector('[role="alert"]');
      if (error) throw new Error(error.textContent);
      if (response.status === 403 || page.querySelector('[name="password"]')) {
        throw new Error('Your session has expired. Sign in again to save.');
      }
      const savedCell = page.getElementById(row.id)?.cells[cell.cellIndex];
      const totals = page.querySelector('.stats');
      if (!response.ok || !response.redirected || !savedCell || !totals) {
        throw new Error('Could not confirm the save. Please try again.');
      }
      cell.replaceChildren(...savedCell.childNodes);
      document.querySelector('.stats').replaceWith(totals);
      message.textContent =
        operation === 'payment' ? 'Status saved.' : 'Details saved.';
      cell.append(message);
    } catch (error) {
      message.textContent =
        error instanceof TypeError
          ? 'Connection interrupted. Check your connection and try again.'
          : error.message;
    } finally {
      controls.forEach((control) => {
        control.disabled = false;
      });
      form.removeAttribute('aria-busy');
    }
  });
});
