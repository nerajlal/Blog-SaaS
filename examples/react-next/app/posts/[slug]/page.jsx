import {notFound} from 'next/navigation';
import Link from 'next/link';
import {getPosts} from '../../api';
async function load(params) {
  const {slug} = await params;
  const data = await getPosts('/' + encodeURIComponent(slug));
  if (!data) notFound();
  return data.blog;
}
export async function generateMetadata({params}) {
  const post = await load(params);
  return {title:post.seo.title,description:post.seo.description,
    alternates:post.seo.canonical_url ? {canonical:post.seo.canonical_url} : undefined,
    robots:{index:post.seo.robots.index,follow:post.seo.robots.follow},
    openGraph:{title:post.seo.open_graph.title,description:post.seo.open_graph.description,
      images:post.seo.open_graph.image_url ? [post.seo.open_graph.image_url] : []}};
}
export default async function Post({params}) {
  const post = await load(params);
  const schema = JSON.stringify(post.seo.schema).replace(/</g,'\\u003c');
  return <article><Link href="/posts">← All posts</Link><h1>{post.title}</h1>
    <p>{post.author_name} {post.author_title}</p>
    {post.cover_image_url && <figure><img src={post.cover_image_url} alt={post.cover_image_alt || ''} style={{maxWidth:'100%'}}/><figcaption>{post.cover_image_caption}</figcaption></figure>}
    {/* HTML is sanitized by the trusted TryWryter API. Do not use arbitrary sources. */}
    <div dangerouslySetInnerHTML={{__html:post.content}}/>
    {post.sections.map(section => <section key={section.id}>
      {section.image_url && <figure><img src={section.image_url} alt={section.image_alt || ''} style={{maxWidth:'100%'}}/><figcaption>{section.image_caption}</figcaption></figure>}
      <div dangerouslySetInnerHTML={{__html:section.text_content}}/>
    </section>)}
    <script type="application/ld+json" dangerouslySetInnerHTML={{__html:schema}}/>
  </article>;
}
