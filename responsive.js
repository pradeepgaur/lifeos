function bindResponsive(){
 const sidebar=$('workspaceNav'),toggle=$('navigationToggle'),backdrop=$('navigationBackdrop'),main=document.querySelector('main'),small=matchMedia('(max-width:1023px)');
 function closeMenu(returnFocus=false){const hadFocus=sidebar.contains(document.activeElement);document.body.classList.remove('nav-open');toggle.setAttribute('aria-expanded','false');backdrop.hidden=true;main.inert=false;sidebar.inert=small.matches;if(returnFocus&&hadFocus)toggle.focus()}
 function openMenu(){if(!small.matches)return;document.body.classList.add('nav-open');toggle.setAttribute('aria-expanded','true');backdrop.hidden=false;sidebar.inert=false;main.inert=true;$('navigationClose').focus()}
 toggle.onclick=()=>document.body.classList.contains('nav-open')?closeMenu(true):openMenu();$('navigationClose').onclick=()=>closeMenu(true);backdrop.onclick=()=>closeMenu(true);
 document.addEventListener('click',e=>{if(small.matches&&e.target.closest('#workspaceNav button[data-view],#workspaceNav button[data-board],#profile'))closeMenu(false)},true);
 document.addEventListener('keydown',e=>{if(!document.body.classList.contains('nav-open'))return;if(e.key==='Escape'){e.preventDefault();closeMenu(true)}if(e.key==='Tab'){const focusable=Array.from(sidebar.querySelectorAll('a[href],button:not([disabled]),input:not([disabled]),select:not([disabled])')).filter(el=>el.getClientRects().length),first=focusable[0],last=focusable.at(-1);if(e.shiftKey&&document.activeElement===first){e.preventDefault();last?.focus()}else if(!e.shiftKey&&document.activeElement===last){e.preventDefault();first?.focus()}}});
 small.addEventListener('change',()=>closeMenu(false));closeMenu(false);
}
