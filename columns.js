// Column gestures share the same permission/version checks as Board settings.
let pendingColumnDelete=null;
function cardDropBefore(column,id,y){return [...column.querySelectorAll('.card')].find(card=>card.dataset.id!==id&&y<card.getBoundingClientRect().top+card.getBoundingClientRect().height/2)?.dataset.id||null}
function cardDropBefore(column,sourceId,y){return [...column.querySelectorAll('.card')].filter(card=>card.dataset.id!==sourceId).find(card=>{const rect=card.getBoundingClientRect();return y<rect.top+rect.height/2})?.dataset.id||null}
function cardMoveChanges(task,status,before){if(task.status!==status)return true;const ids=state.tasks.filter(t=>t.board===task.board&&t.status===status).map(t=>t.id),at=ids.indexOf(task.id);return (ids[at+1]||null)!==before}
function openColumnDelete(id){
 if(!canEdit())return;const column=state.columnRows[board]?.find(c=>c.id===id),targets=(state.columnRows[board]||[]).filter(c=>c.id!==id);if(!column)return;
 if(!targets.length){toast('Keep at least one column on your board.');return;}
 pendingColumnDelete={board,id,version:column.version};const count=state.tasks.filter(t=>t.board===board&&t.status===id).length;
 $('columnDeleteTitle').textContent='Delete “'+column.name+'”?';$('columnDeleteMessage').textContent=count?count+' task'+(count===1?'':'s')+' will move to the column you choose.':'Choose where to keep any tasks in this column.';
 $('columnDeleteDestination').innerHTML=targets.map(c=>`<option value="${esc(c.id)}">${esc(c.name)}</option>`).join('');$('columnDeleteDialog').showModal();
}
function bindBoardColumns(){
 let moving=null,cardMoving=null;
 const panel=$('board');
 function clearCardMarkers(){panel.querySelectorAll('.card-drop-before,.card-drop-after,.dragover').forEach(el=>el.classList.remove('card-drop-before','card-drop-after','dragover'))}
 function clearCardDrag(){clearCardMarkers();panel.querySelectorAll('.card-moving').forEach(el=>el.classList.remove('card-moving'));cardMoving=null;delete document.body.dataset.cardDragging}
 $('columnDeleteForm').onsubmit=async e=>{e.preventDefault();if(!pendingColumnDelete)return;const choice={...pendingColumnDelete,moveTo:$('columnDeleteDestination').value};$('columnDeleteSubmit').disabled=true;try{await mutate('column_delete',choice);$('columnDeleteDialog').close();pendingColumnDelete=null;setView(view);toast('Column deleted. Tasks preserved.')}catch{}finally{$('columnDeleteSubmit').disabled=false}};
 function begin(column){moving={column,board,order:[...panel.querySelectorAll('.column')].map(el=>el.dataset.status)};column.classList.add('column-moving');document.body.dataset.columnDragging='true'}
 function position(x,y){if(!moving)return;const rect=panel.getBoundingClientRect();if(x<rect.left+45)panel.scrollLeft-=20;else if(x>rect.right-45)panel.scrollLeft+=20;const target=document.elementFromPoint(x,y)?.closest('.column');if(!target||target===moving.column||!panel.contains(target))return;const bounds=target.getBoundingClientRect();panel.insertBefore(moving.column,x<bounds.left+bounds.width/2?target:target.nextSibling)}
 async function finish(cancel=false){if(!moving)return;const drag=moving;moving=null;delete document.body.dataset.columnDragging;drag.column.classList.remove('column-moving');const order=[...panel.querySelectorAll('.column')].map(el=>el.dataset.status);if(cancel){setView(view);return}if(order.join('|')===drag.order.join('|'))return;try{await mutate('column_reorder',{board:drag.board,order})}catch{setView(view)}}
 panel.addEventListener('click',e=>{
  const remove=e.target.closest('[data-column-delete]');if(remove){openColumnDelete(remove.dataset.columnDelete);return;}
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
 panel.addEventListener('dragstart',e=>{if(e.target.closest('input')){e.preventDefault();return}const card=e.target.closest('.card');if(card&&canEdit()){const task=state.tasks.find(t=>t.id===card.dataset.id);if(!task)return;cardMoving={...task};document.body.dataset.cardDragging='true';card.classList.add('card-moving');e.dataTransfer.setData('text/plain',card.dataset.id);e.dataTransfer.effectAllowed='move';return}const head=e.target.closest('.column-head');if(head&&canEdit()){begin(head.closest('.column'));e.dataTransfer.setData('application/x-lifeos-column',head.closest('.column').dataset.status);e.dataTransfer.effectAllowed='move'}});
 panel.addEventListener('dragover',e=>{const col=e.target.closest('.column');if(!canEdit())return;if(moving){e.preventDefault();position(e.clientX,e.clientY)}else if(col){e.preventDefault();clearCardMarkers();col.classList.add('dragover');if(cardMoving){const rect=col.getBoundingClientRect();if(e.clientY<rect.top+50)col.scrollTop-=12;else if(e.clientY>rect.bottom-50)col.scrollTop+=12;const before=cardDropBefore(col,cardMoving.id,e.clientY),cards=[...col.querySelectorAll('.card')].filter(c=>c.dataset.id!==cardMoving.id);if(before)cards.find(c=>c.dataset.id===before)?.classList.add('card-drop-before');else cards.at(-1)?.classList.add('card-drop-after')}}});
 panel.addEventListener('dragleave',e=>e.target.closest('.column')?.classList.remove('dragover'));
 panel.addEventListener('drop',e=>{if(!canEdit())return;e.preventDefault();if(moving){run(finish());return}const col=e.target.closest('.column');if(!col){clearCardDrag();return}const t=cardMoving||state.tasks.find(t=>t.id===e.dataTransfer.getData('text/plain')),before=t?cardDropBefore(col,t.id,e.clientY):null,status=col.dataset.status;clearCardDrag();if(t&&cardMoveChanges(t,status,before))run(mutate('task_move',{id:t.id,version:t.version,status,before}))});
 panel.addEventListener('dragend',()=>{if(moving)run(finish(true));clearCardDrag()});
 document.addEventListener('keydown',e=>{if(e.key==='Escape'&&moving){e.preventDefault();run(finish(true))}});
}
