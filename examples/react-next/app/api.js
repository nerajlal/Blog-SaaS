// Server-only module: never import this into a component marked "use client".
import 'server-only';
export async function getPosts(path = '') {
  const origin = process.env.TRYWRYTER_URL;
  const key = process.env.TRYWRYTER_API_KEY;
  if (!origin || !key) throw new Error('Configure TryWryter server environment variables.');
  const response = await fetch(origin.replace(/\/$/, '') + '/api/v1/projects/blogs' + path, {
    headers: {'X-API-KEY':key}, cache:'no-store'
  });
  if (response.status === 404) return null;
  if (!response.ok) throw new Error('TryWryter could not load posts.');
  return response.json();
}
