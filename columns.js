// Column gestures share the same permission/version checks as Board settings.
function bindBoardColumns(){
 let moving=null;
 const panel=$('board');
 function begin(column){moving={column,board,order:[...panel.querySelectorAll('.column')].map(el=>el.dataset.status)};column.classList.add('column-moving');document.body.dataset.columnDragging='true'}
 function position(x,y){if(!moving)return;const rect=panel.getBoundingClientRect();if(x<rect.left+45)panel.scrollLeft-=20;else if(x>rect.right-45)panel.scrollLeft+=20;const target=document.elementFromPoint(x,y)?.closest('.column');if(!target||target===moving.column||!panel.contains(target))return;const bounds=target.getBoundingClientRect();panel.insertBefore(moving.column,x<bounds.left+bounds.width/2?target:target.nextSibling)}
 async function finish(cancel=false){if(!moving)return;const drag=moving;moving=null;delete document.body.dataset.columnDragging;drag.column.classList.remove('column-moving');const order=[...panel.querySelectorAll('.column')].map(el=>el.dataset.status);if(cancel){setView(view);return}if(order.join('|')===drag.order.join('|'))return;try{await mutate('column_reorder',{board:drag.board,order})}catch{setView(view)}}
 panel.addEventListener('click',e=>{
  const button=e.target.closest('[data-rename-column]');if(!button||!canEdit()||moving)return;
  const id=button.dataset.renameColumn,bid=board,column=state.columnRows[bid].find(c=>c.id===id);if(!column)return;
  const input=document.createElement('input');input.className='column-name-input';input.value=column.name;input.maxLength=80;input.setAttribute('aria-label','Column name');button.replaceWith(input);input.focus();input.select();let finished=false;
  async function save(cancel=false){if(finished)return;const name=input.value.trim();if(!cancel&&!name){input.focus();return}finished=true;input.disabled=true;if(cancel||name===column.name){input.replaceWith(button);return}try{await mutate('column_update',{board:bid,id,version:column.version,name,done:column.done})}catch{setView(view)}}
  input.addEventListener('keydown',e=>{if(e.isComposing)return;if(e.key==='Enter'){e.preventDefault();run(save())}if(e.key==='Escape'){e.preventDefault();run(save(true))}});
  input.addEventListener('blur',()=>{if(!input.value.trim())run(save(true));else run(save())});
 });
 panel.addEventListener('pointerdown',e=>{const grip=e.target.closest('[data-column-grip]');if(!grip||!canEdit()||e.button!==0)return;e.preventDefault();begin(grip.closest('.column'));grip.setPointerCapture(e.pointerId);
  const move=event=>position(event.clientX,event.clientY),up=event=>{grip.removeEventListener('pointermove',move);grip.removeEventListener('pointerup',up);grip.removeEventListener('pointercancel',up);run(finish(event.type==='pointercancel'))};grip.addEventListener('pointermove',move);grip.addEventListener('pointerup',up);grip.addEventListener('pointercancel',up);
 });
 panel.addEventListener('dragstart',e=>{if(e.target.closest('input')){e.preventDefault();return}const card=e.target.closest('.card');if(card&&canEdit()){e.dataTransfer.setData('text/plain',card.dataset.id);e.dataTransfer.effectAllowed='move';return}const head=e.target.closest('.column-head');if(head&&canEdit()){begin(head.closest('.column'));e.dataTransfer.setData('application/x-lifeos-column',head.closest('.column').dataset.status);e.dataTransfer.effectAllowed='move'}});
 panel.addEventListener('dragover',e=>{const col=e.target.closest('.column');if(!canEdit())return;if(moving){e.preventDefault();position(e.clientX,e.clientY)}else if(col){e.preventDefault();col.classList.add('dragover')}});
 panel.addEventListener('dragleave',e=>e.target.closest('.column')?.classList.remove('dragover'));
 panel.addEventListener('drop',e=>{if(!canEdit())return;e.preventDefault();if(moving){run(finish());return}const col=e.target.closest('.column');if(!col)return;col.classList.remove('dragover');const t=state.tasks.find(t=>t.id===e.dataTransfer.getData('text/plain'));if(t&&t.status!==col.dataset.status)run(mutate('task_move',{id:t.id,version:t.version,status:col.dataset.status}))});
 panel.addEventListener('dragend',()=>{if(moving)run(finish(true));panel.querySelectorAll('.dragover').forEach(el=>el.classList.remove('dragover'))});
 document.addEventListener('keydown',e=>{if(e.key==='Escape'&&moving){e.preventDefault();run(finish(true))}});
}
