const assert=require('assert/strict');
module.exports=async({owner,bid,unrelatedTask,fixture})=>{
 const one=(await owner.post('column_create',{board:bid,name:'Order one'})).id,two=(await owner.post('column_create',{board:bid,name:'Order two'})).id;
 const ids=[];for(const [name,status]of [['A',one],['B',one],['C',one],['D',two]])ids.push((await owner.post('task_create',{board:bid,status,title:'Order '+name})).id);
 const [a,b,c,d]=ids;async function order(column){return (await owner.get('state')).tasks.filter(t=>t.board===bid&&t.status===column).map(t=>t.id)}
 async function move(id,status,before=null,expected=200,version=null){const t=(await owner.get('state')).tasks.find(t=>t.id===id);return owner.post('task_move',{id,status,before,version:version===null?t.version:version},expected)}
 fixture('task-order-downgrade');assert.deepEqual(await order(one),[a,b,c],'Upgrade must preserve legacy card ordering');await move(c,one,a);assert.deepEqual(await order(one),[c,a,b]);await move(b,one,c);assert.deepEqual(await order(one),[b,c,a]);await move(b,one);assert.deepEqual(await order(one),[c,a,b]);
 await move(d,one,a);assert.deepEqual(await order(one),[c,d,a,b]);await move(d,one,b);assert.deepEqual(await order(one),[c,a,d,b]);
 const stale=(await owner.get('state')).tasks.find(t=>t.id===d).version;await move(d,two);await move(d,one,null,409,stale);assert.deepEqual(await order(two),[d]);
 await move(a,one,unrelatedTask,409);assert.deepEqual(await order(one),[c,a,b]);await move(a,one,a,409);
 let snapshot=await owner.get('state'),task=snapshot.tasks.find(t=>t.id===d);await owner.post('task_update',{...task,status:one});assert.deepEqual(await order(one),[c,a,b,d]);
 snapshot=await owner.get('state');task=snapshot.tasks.find(t=>t.id===a);await owner.post('task_update',{...task,title:'Renamed without reordering'});assert.deepEqual(await order(one),[c,a,b,d]);
 const target=(await owner.post('task_create',{board:bid,status:two,title:'Destination existing task'})).id;ids.push(target);snapshot=await owner.get('state');await owner.post('column_delete',{board:bid,id:one,version:snapshot.columns[bid].find(c=>c.id===one).version,moveTo:two});assert.deepEqual(await order(two),[target,c,a,b,d]);
 for(const id of ids){const t=(await owner.get('state')).tasks.find(t=>t.id===id);await owner.post('task_delete',{id,version:t.version})}snapshot=await owner.get('state');await owner.post('column_delete',{board:bid,id:two,version:snapshot.columns[bid].find(c=>c.id===two).version,moveTo:'todo'});
 console.log('PASS: persistent same-column reorder, exact cross-column placement, append, stale/invalid-anchor protection, edit stability, and ordered task preservation on column deletion.');
};
