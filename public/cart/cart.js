'use strict';

function setCartStatus(msg) {
  const el = document.getElementById('cart-status');
  if (el) el.textContent = msg || '';
}

async function loadCart() {
  hideBanner();
  setCartStatus('');
  const tbody = document.getElementById('cart-rows');
  const totalEl = document.getElementById('cart-total');
  if (!tbody || !totalEl) return;

  const res = await apiFetch('/api/cart');
  if (!res) return; // 503 or network error already handled by apiFetch()

  let body = null;
  try {
    body = await res.json();
  } catch (e) {
    showBanner('Invalid JSON response from /api/cart.');
    return;
  }

  if (res.status === 401) {
    tbody.innerHTML = '';
    totalEl.textContent = 'Total: $0.00';
    showBanner('Please sign in first (401 AUTH_REQUIRED) to view and manage your cart.');
    return;
  }

  if (!res.ok) {
    showBanner(body && body.error ? `Error: ${body.error}` : `Request failed (${res.status}).`);
    return;
  }

  const items = Array.isArray(body.data) ? body.data : [];
  const total = Number(body.total || 0);

  if (items.length === 0) {
    tbody.innerHTML = '<tr><td colspan="6">Your cart is empty.</td></tr>';
  } else {
    tbody.innerHTML = items.map((item) => `
      <tr data-id="${Number(item.id)}">
        <td>${Number(item.id)}</td>
        <td>${Number(item.product_id)}</td>
        <td>$${Number(item.unit_price).toFixed(2)}</td>
        <td>
          <input
            type="number"
            class="qty-input"
            id="qty-${Number(item.id)}"
            min="1"
            value="${Number(item.qty)}"
          >
        </td>
        <td>$${Number(item.subtotal).toFixed(2)}</td>
        <td>
          <button type="button" onclick="updateCartItem(${Number(item.id)})">Update</button>
          <button type="button" onclick="deleteCartItem(${Number(item.id)})">Remove</button>
        </td>
      </tr>
    `).join('');
  }

  totalEl.textContent = `Total: $${total.toFixed(2)}`;
}

async function addCartItem() {
  hideBanner();
  const pidInput = document.getElementById('add-product-id');
  const qtyInput = document.getElementById('add-qty');
  const product_id = Number(pidInput ? pidInput.value : 0);
  const qty = Number(qtyInput ? qtyInput.value : 0);

  if (!Number.isInteger(qty) || qty < 1) {
    showBanner('Validation error: qty must be >= 1.');
    return;
  }

  const res = await apiFetch('/api/cart', {
    method: 'POST',
    headers: { 'Content-Type': 'application/json' },
    body: JSON.stringify({ product_id, qty }),
  });
  if (!res) return;

  let body = null;
  try {
    body = await res.json();
  } catch (e) {
    body = null;
  }

  if (res.status === 401) {
    showBanner('Authentication required (401 AUTH_REQUIRED). Please log in first.');
    return;
  }
  if (res.status === 404) {
    showBanner('Product not found (404 PRODUCT_NOT_FOUND).');
    return;
  }
  if (res.status === 422) {
    showBanner('Validation error (422): qty must be >= 1.');
    return;
  }
  if (!res.ok) {
    showBanner(body && body.error ? `Error: ${body.error}` : `Failed to add item (${res.status}).`);
    return;
  }

  setCartStatus('Item added to cart.');
  await loadCart();
}

async function updateCartItem(id) {
  hideBanner();
  const input = document.getElementById(`qty-${id}`);
  const qty = Number(input ? input.value : 0);

  if (!Number.isInteger(qty) || qty < 1) {
    showBanner('Validation error: qty must be >= 1.');
    return;
  }

  const res = await apiFetch(`/api/cart/${id}`, {
    method: 'PATCH',
    headers: { 'Content-Type': 'application/json' },
    body: JSON.stringify({ qty }),
  });
  if (!res) return;

  let body = null;
  try {
    body = await res.json();
  } catch (e) {
    body = null;
  }

  if (res.status === 404) {
    showBanner('Cart item not found (404 NOT_FOUND).');
    await loadCart();
    return;
  }
  if (!res.ok) {
    showBanner(body && body.error ? `Error: ${body.error}` : `Failed to update item (${res.status}).`);
    return;
  }

  setCartStatus('Quantity updated.');
  await loadCart();
}

async function deleteCartItem(id) {
  hideBanner();
  const res = await apiFetch(`/api/cart/${id}`, {
    method: 'DELETE',
  });
  if (!res) return;

  let body = null;
  try {
    body = await res.json();
  } catch (e) {
    body = null;
  }

  if (res.status === 404) {
    showBanner('Cart item not found (404 NOT_FOUND).');
    await loadCart();
    return;
  }
  if (!res.ok) {
    showBanner(body && body.error ? `Error: ${body.error}` : `Failed to remove item (${res.status}).`);
    return;
  }

  setCartStatus('Item removed.');
  await loadCart();
}

document.addEventListener('DOMContentLoaded', () => {
  const addBtn = document.getElementById('add-cart-btn');
  if (addBtn) addBtn.addEventListener('click', addCartItem);
  loadCart();
});