'use strict';
const form=document.getElementById('accessForm'),status=document.getElementById('formStatus');
let accessToken='';
async function loadAccessToken(){const r=await fetch('request.php',{cache:'no-store',credentials:'same-origin'});const data=await r.json();if(!r.ok||!data.csrf)throw Error('The request form is temporarily unavailable. Please try again.');accessToken=data.csrf;return accessToken}
if(form){
 try{const zone=Intl.DateTimeFormat().resolvedOptions().timeZone;if(zone)form.elements.timezone.value=zone}catch{}
 loadAccessToken().catch(()=>{});
 form.addEventListener('submit',async event=>{event.preventDefault();if(!form.reportValidity())return;const button=form.querySelector('[type="submit"]');button.disabled=true;status.className='';status.textContent='Sending your request…';
  try{if(!accessToken)await loadAccessToken();const data=Object.fromEntries(new FormData(form));const response=await fetch('request.php',{method:'POST',credentials:'same-origin',headers:{'Content-Type':'application/json','X-CSRF-Token':accessToken},body:JSON.stringify(data)});const result=await response.json();if(!response.ok){if(response.status===419)accessToken='';throw Error(result.error||'Your request could not be saved. Please try again.')}status.className='success';status.textContent='You’re on the list. Your request is saved. We’ll contact you at the email you provided about availability and next steps. Your account has not been created yet.';form.reset();button.textContent='Request received ✓';button.disabled=true;status.scrollIntoView({block:'nearest',behavior:matchMedia('(prefers-reduced-motion: reduce)').matches?'auto':'smooth'});
  }catch(error){status.className='error';status.textContent=error.message||'Connection interrupted. Please try again.';button.disabled=false}
 });
}
const tabs=[...document.querySelectorAll('[data-tour]')];
function selectTour(tab){for(const button of tabs){const selected=button===tab;button.setAttribute('aria-selected',String(selected));button.tabIndex=selected?0:-1;document.getElementById('tour-'+button.dataset.tour).hidden=!selected}}
for(const tab of tabs){tab.addEventListener('click',()=>selectTour(tab));tab.addEventListener('keydown',event=>{let index=tabs.indexOf(tab);if(event.key==='ArrowRight'||event.key==='ArrowDown')index=(index+1)%tabs.length;else if(event.key==='ArrowLeft'||event.key==='ArrowUp')index=(index+tabs.length-1)%tabs.length;else if(event.key==='Home')index=0;else if(event.key==='End')index=tabs.length-1;else return;event.preventDefault();selectTour(tabs[index]);tabs[index].focus()})}
