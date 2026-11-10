(() => {
 const toggle=document.querySelector('.menu-toggle'),menu=document.getElementById('mobile-menu');
 const closeMenu=()=>{if(!menu||!toggle)return;menu.hidden=true;toggle.setAttribute('aria-expanded','false');toggle.setAttribute('aria-label','Open navigation');};
 toggle?.addEventListener('click',()=>{const open=toggle.getAttribute('aria-expanded')!=='true';menu.hidden=!open;toggle.setAttribute('aria-expanded',String(open));toggle.setAttribute('aria-label',open?'Close navigation':'Open navigation');});
 menu?.querySelectorAll('a').forEach(a=>a.addEventListener('click',closeMenu));
 document.addEventListener('keydown',e=>{if(e.key==='Escape'&&menu&&!menu.hidden){closeMenu();toggle.focus();}});
 document.addEventListener('click',e=>{if(menu&&!menu.hidden&&!menu.contains(e.target)&&!toggle.contains(e.target))closeMenu();});
 window.matchMedia('(min-width: 768px)').addEventListener('change',closeMenu);
 const tabs=[...document.querySelectorAll('[data-demo-tab]')];
 const activate=tab=>{tabs.forEach(t=>{const active=t===tab;t.setAttribute('aria-selected',String(active));t.tabIndex=active?0:-1;const panel=document.getElementById(t.getAttribute('aria-controls'));panel.hidden=!active;if(active&&!window.matchMedia('(prefers-reduced-motion: reduce)').matches)panel.animate?.([{opacity:.3,translate:'0 5px'},{opacity:1,translate:'0 0'}],{duration:220,easing:'ease-out'});});};
 tabs.forEach((tab,index)=>{tab.addEventListener('click',()=>activate(tab));tab.addEventListener('keydown',event=>{let next;if(event.key==='ArrowRight')next=(index+1)%tabs.length;else if(event.key==='ArrowLeft')next=(index-1+tabs.length)%tabs.length;else if(event.key==='Home')next=0;else if(event.key==='End')next=tabs.length-1;else return;event.preventDefault();activate(tabs[next]);tabs[next].focus();});});
 // Animate only visible illustrations; all content remains readable without JavaScript.
 const motion=window.matchMedia('(prefers-reduced-motion: reduce)');
 const revealTargets=[...document.querySelectorAll('.section-heading,.agency-benefits article,.context-card,.ai-copy,.workflow-grid article,.feature-card,.developer-grid>div,.faq-list,.final-cta')];
 const illustrated=[...document.querySelectorAll('.feature-card,.workflow-grid article,.context-card')];
 const revealed=new WeakSet();
 const observer='IntersectionObserver' in window?new IntersectionObserver(entries=>entries.forEach(entry=>{
   const element=entry.target;
   element.classList.toggle('motion-visible',entry.isIntersecting);
   if(!entry.isIntersecting||motion.matches||revealed.has(element))return;
   revealed.add(element);
   const parent=element.parentElement;
   const index=parent?.matches('.feature-grid,.workflow-grid')?[...parent.children].indexOf(element)%3:0;
   element.animate?.([{opacity:0,translate:'0 18px'},{opacity:1,translate:'0 0'}],{duration:600,delay:index*70,easing:'cubic-bezier(.2,.7,.3,1)',fill:'backwards'});
 }),{threshold:.12}):null;
 [...new Set([...revealTargets,...illustrated])].forEach(element=>observer?.observe(element));
 motion.addEventListener('change',()=>{if(motion.matches)document.getAnimations().forEach(animation=>animation.cancel());});
 document.addEventListener('visibilitychange',()=>document.body.classList.toggle('motion-paused',document.hidden));
 let timer;
 document.getElementById('copy-code')?.addEventListener('click',async()=>{const status=document.getElementById('copy-status');try{await navigator.clipboard.writeText(document.getElementById('api-example').textContent);status.textContent='API example copied.';}catch{status.textContent='Select the example and copy it manually.';}status.hidden=false;clearTimeout(timer);timer=setTimeout(()=>status.hidden=true,3500);});
})();
