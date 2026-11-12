import Link from 'next/link';
import {getPosts} from '../api';
export default async function Posts({searchParams}) {
  const params = await searchParams;
  const page = Math.max(1, Number.parseInt(params.page || '1', 10) || 1);
  const data = await getPosts(`?page=${page}&per_page=12`);
  return <main><h1>{data.project}</h1>{data.blogs.length ? data.blogs.map(post => <article key={post.id}>
    <h2><Link href={'/posts/' + encodeURIComponent(post.slug)}>{post.title}</Link></h2>
    <p>{post.excerpt}</p>
  </article>) : <p>No published posts yet.</p>}
  <nav>{data.meta.current_page > 1 && <Link href={'?page='+(data.meta.current_page-1)}>Previous </Link>}
    {data.meta.current_page < data.meta.last_page && <Link href={'?page='+(data.meta.current_page+1)}>Next</Link>}</nav></main>;
}
