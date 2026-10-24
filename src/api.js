const API = import.meta.env.VITE_API_URL || '/api/index.php';

export async function api(resource, { method = 'GET', params = {}, body } = {}) {
  const url = new URL(API, window.location.origin);
  url.searchParams.set('resource', resource);
  Object.entries(params).forEach(([key, value]) => value != null && url.searchParams.set(key, value));
  const response = await fetch(url, {
    method,
    credentials: 'include',
    headers: body ? { 'Content-Type': 'application/json' } : {},
    body: body ? JSON.stringify(body) : undefined,
  });
  const data = await response.json();
  if (!response.ok) throw new Error(data.error || 'The request could not be completed.');
  return data;
}
