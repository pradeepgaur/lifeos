const fs=require('fs'),path=require('path'),assert=require('assert/strict'),{spawn,spawnSync}=require('child_process'),crypto=require('crypto');
const root=path.resolve(__dirname,'..'),php=process.env.LIFEOS_PHP||'C:/xampp/php/php.exe',name='lifeos_test_'+crypto.randomBytes(6).toString('hex'),runtime=path.join(__dirname,'.runtime',name),port=18700+crypto.randomInt(200),base=`http://127.0.0.1:${port}/`;
fs.mkdirSync(runtime,{recursive:true});let server;
function fixture(action){const r=spawnSync(php,[path.join(__dirname,'db-fixture.php'),action,name],{encoding:'utf8'});if(r.status!==0)throw Error(r.stderr||r.stdout||'DB fixture failed')}
class Client{
 constructor(){this.cookie='';this.csrf=''}
 async request(url,options={}){const r=await fetch(base+url,{...options,redirect:'manual',headers:{...options.headers,Cookie:this.cookie}});for(const c of r.headers.getSetCookie()){const pair=c.split(';')[0];const key=pair.split('=')[0];this.cookie=this.cookie.split('; ').filter(x=>x&&!x.startsWith(key+'=')).concat(pair).join('; ')}return r}
 async get(action){const r=await this.request('api.php?action='+action);return {status:r.status,...await r.json()}}
 async session(){const r=await this.get('session');this.csrf=r.csrf;return r}
 async post(action,data={},expected=200){const r=await this.request('api.php',{method:'POST',headers:{'Content-Type':'application/json','X-CSRF-Token':this.csrf},body:JSON.stringify({action,...data})});const body=await r.json();assert.equal(r.status,expected,action+': '+JSON.stringify(body));if(body.csrf)this.csrf=body.csrf;return body}
}
const password='LifeOS-test-pass-2026';
(async()=>{fixture('create');try{
 server=spawn(php,['-d','session.save_path='+runtime,'-S',`127.0.0.1:${port}`,'-t',root],{cwd:root,env:{...process.env,LIFEOS_CONFIG:path.join(runtime,'config.php')},stdio:['ignore','ignore','pipe']});let logs='';server.stderr.on('data',d=>logs+=d);
 for(let n=0;n<40;n++){try{await fetch(base+'login.php',{redirect:'manual'});break}catch{await new Promise(r=>setTimeout(r,100))}}
 const owner=new Client(),anon=new Client();let r=await owner.request('setup.php');
 const policy=r.headers.get('content-security-policy');assert(/img-src[^;]*\bblob:/.test(policy),'Browser image optimization must be allowed to decode local blob URLs');assert(/script-src 'self';/.test(policy),'Script policy must stay restricted');
 const html=await r.text();const csrf=html.match(/name="csrf" value="([a-f0-9]+)"/)[1];
 const setup=new URLSearchParams({csrf,host:'127.0.0.1',port:'3306',database:name,username:process.env.LIFEOS_TEST_DB_USER||'root',dbpassword:process.env.LIFEOS_TEST_DB_PASSWORD||'',name:'Owner',email:'owner@example.test',password});
 r=await owner.request('setup.php',{method:'POST',body:setup});assert.equal(r.status,302,'Installer: '+await r.text()+' LOG '+logs);assert(fs.existsSync(path.join(runtime,'config.php')));
 r=await anon.request('setup.php');assert.equal(r.status,302,'Installer locks');
 await owner.session();assert.equal((await anon.get('state')).status,401);
 const csrfSaved=owner.csrf;owner.csrf='bad';await owner.post('board_create',{name:'CSRF blocked'},419);owner.csrf=csrfSaved;
 let s=await owner.get('state');assert.equal(s.boards.length,1);const bid=s.boards[0].id;assert.equal(s.columns[bid].length,4);
 assert.equal((await owner.get('state&revision='+s.revision)).unchanged,true);
 assert.equal(s.user.fontScale,112);await owner.post('profile_update',{name:s.user.name,fontScale:125});assert.equal((await owner.get('state')).user.fontScale,125);await owner.post('profile_update',{name:s.user.name,fontScale:999},400);
 const goal=(await owner.post('goal_create',{board:bid,name:'Build something <great>',description:'Goal description',priority:'High'})).id;
 const routine=(await owner.post('schedule_create',{board:bid,title:'Daily walk',description:'Get moving',days:[1,2,3,4,5,6,7],startTime:'07:00',endTime:'07:30',startDate:'2026-01-01',trackHabit:true,goal,color:'green'})).id;
 await owner.post('habit_check',{id:routine,day:'2026-11-15',checked:true});
 await owner.post('habit_check',{id:routine,day:'2025-12-01',checked:true},400);
 await owner.post('schedule_create',{board:bid,title:'Invalid time',days:[1],startTime:'23:00',endTime:'01:00',startDate:'2026-01-01'},400);
 const task=(await owner.post('task_create',{board:bid,title:'Integration task',status:'todo',description:'Details',area:'Work',priority:'High',goal,assignee:s.user.id,due:'2026-11-20',checks:[{text:'First step',done:true}]})).id;
 s=await owner.get('state');let t=s.tasks.find(t=>t.id===task);assert.equal(t.checks[0].done,true);assert(t.createdAt.endsWith('Z'));const created=t.createdAt;
 await owner.post('task_update',{...t,title:'Updated task',version:t.version});await owner.post('task_update',{...t,title:'Stale overwrite'},409);
 await owner.post('task_create',{board:bid,title:'Invalid date',status:'todo',due:'2026-02-31'},400);
 const other=(await owner.post('board_create',{name:'Second board'})).id;
 await owner.post('task_create',{board:other,status:'todo',title:'Wrong goal',goal},400);
 const col=(await owner.post('column_create',{board:bid,name:'Ready',done:true})).id;
 s=await owner.get('state');let order=s.columns[bid].map(c=>c.id).reverse();await owner.post('column_reorder',{board:bid,order});s=await owner.get('state');assert.equal(s.columns[bid][0].id,col);
 t=s.tasks.find(t=>t.id===task);await owner.post('task_move',{id:task,status:col,version:t.version});
 let token=(await owner.post('invite_create',{board:bid,email:'friend@example.test',role:'reviewer'})).token;
 assert.equal((await anon.get('invitation&token='+token)).invitation.role,'reviewer');
 const friend=new Client();await friend.session();await friend.post('register',{name:'Friend',email:'wrong@example.test',password,token},403);await friend.post('register',{name:'Friend',email:'friend@example.test',password,token});
 let f=await friend.get('state');assert.equal(f.schedule.length,1);assert.equal(f.habitChecks.length,0);await friend.post('schedule_create',{board:bid,title:'Denied'},403);await friend.post('habit_check',{id:routine,day:'2026-11-14',checked:true});assert.equal((await friend.get('state')).habitChecks.length,1);assert.equal((await owner.get('state')).habitChecks.length,1);assert.equal(f.boards.length,1);assert(!f.boards.some(b=>b.id===other));await friend.post('task_create',{board:bid,status:'todo',title:'Unauthorized'},403);await friend.post('goal_create',{board:other,name:'Hidden'},404);
 await friend.post('comment_add',{id:task,text:'Review looks good'});s=await owner.get('state');t=s.tasks.find(t=>t.id===task);assert.equal(t.comments.length,1);assert.equal(t.comments[0].author,'Friend');assert.equal(t.createdAt,created);
 await owner.post('member_update',{board:bid,userId:f.user.id,role:'viewer'});await friend.post('comment_add',{id:task,text:'Forbidden'},403);
 await owner.post('member_update',{board:bid,userId:f.user.id,role:'editor'});await friend.post('task_create',{board:bid,status:'todo',title:'Collaborator task'});await friend.post('invite_create',{board:bid,email:'other@example.test',role:'editor'},403);
 s=await owner.get('state');const entry=s.schedule.find(e=>e.id===routine);await owner.post('schedule_update',{...entry,title:'Daily walk updated'});await owner.post('schedule_update',{...entry,title:'Stale schedule'},409);
 await owner.post('profile_update',{name:'Owner updated',theme:'linear'});assert.equal((await owner.get('state')).user.theme,'linear');assert.equal((await owner.get('state')).user.fontScale,125);
 s=await owner.get('state');const c=s.columns[bid].find(c=>c.id===col);await owner.post('column_delete',{board:bid,id:col,version:c.version,moveTo:'done'});s=await owner.get('state');assert.equal(s.tasks.find(t=>t.id===task).status,'done');
 const g=s.goals.find(g=>g.id===goal);await owner.post('goal_delete',{id:goal,version:g.version});s=await owner.get('state');assert.equal(s.tasks.find(t=>t.id===task).goal,'');
 await owner.post('member_remove',{board:bid,userId:f.user.id});await friend.post('comment_add',{id:task,text:'Removed member'},404);assert.equal((await friend.get('state')).tasks.length,0);
 token=(await owner.post('invite_create',{board:other,email:'friend@example.test',role:'viewer'})).token;await friend.post('invite_accept',{token});assert.equal((await friend.get('state')).boards[0].id,other);await friend.post('invite_accept',{token},403);
 s=await owner.get('state');assert.equal(s.schedule.find(e=>e.id===routine).goal,'');const updatedRoutine=s.schedule.find(e=>e.id===routine);await owner.post('schedule_delete',{id:routine,version:updatedRoutine.version});assert.equal((await owner.get('state')).habitChecks.length,0);
 const imported=await owner.post('prototype_import',{data:{boards:['Old board'],columns:{'Old board':{custom:'Custom'}},goals:[{id:'old-goal',name:'Old goal'}],tasks:[{board:'Old board',status:'custom',title:'Old task',goal:'old-goal',checks:[],comments:[],createdAt:'2026-11-01T00:00:00Z'}]}});s=await owner.get('state');const importedTask=s.tasks.find(t=>t.board===imported.board);assert(importedTask);assert.equal(importedTask.createdAt,'2026-11-01T00:00:00Z');assert(importedTask.goal);assert.equal(s.goals.find(g=>g.board===imported.board).createdAt,null);
 const logged=new Client();await logged.session();await logged.post('login',{email:'owner@example.test',password:'wrong'},401);await logged.post('login',{email:'owner@example.test',password});
 await owner.post('password_change',{currentPassword:password,password:'Changed-password-2026'});assert.equal((await logged.get('state')).status,401);
 s=await owner.get('state');const b=s.boards.find(b=>b.id===other);await owner.post('board_delete',{id:other,version:b.version});assert.equal((await friend.get('state')).boards.length,0);
 t=s.tasks.find(t=>t.id===task);await owner.post('task_delete',{id:task,version:t.version});s=await owner.get('state');assert(!s.tasks.some(t=>t.id===task));
 fixture('drop-backgrounds');
 const imageForm=new FormData();imageForm.append('board',bid);imageForm.append('version',String(s.boards.find(b=>b.id===bid).version));imageForm.append('image',new Blob([Buffer.from('iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAQAAAC1HAwCAAAAC0lEQVR42mP8/x8AAusB9Wl6CBsAAAAASUVORK5CYII=','base64')],{type:'image/png'}),'test.png');
 r=await owner.request('background.php',{method:'POST',headers:{'X-CSRF-Token':owner.csrf},body:imageForm});assert.equal(r.status,200,await r.text());
 r=await owner.request('background.php?board='+bid);assert.equal(r.status,200);assert.equal(r.headers.get('content-type'),'image/png');const etag=r.headers.get('etag');await r.arrayBuffer();
 r=await owner.request('background.php?board='+bid,{headers:{'If-None-Match':etag}});assert.equal(r.status,304);
 r=await friend.request('background.php?board='+bid);assert.equal(r.status,404);r=await anon.request('background.php?board='+bid);assert.equal(r.status,401);
 s=await owner.get('state');assert.equal(Number(s.boards.find(b=>b.id===bid).hasBackground),1);
 const removeForm=new FormData();removeForm.append('board',bid);removeForm.append('version',String(s.boards.find(b=>b.id===bid).version));removeForm.append('remove','1');r=await owner.request('background.php',{method:'POST',headers:{'X-CSRF-Token':owner.csrf},body:removeForm});assert.equal(r.status,200);assert.equal((await owner.request('background.php?board='+bid)).status,404);
 fixture('downgrade');assert.equal((await owner.get('state')).schedule.length,0);
 if(process.env.LIFEOS_PREVIEW){console.log('Preview: '+base);await new Promise(resolve=>process.on('SIGINT',resolve))}
 await owner.post('logout');assert.equal((await owner.get('state')).status,401);
 console.log('PASS: installer, setup lock, sessions, CSRF, board/column/goal/task CRUD, validation, assignment, timestamps, poll revisions, conflicts, comments, invitation registration/acceptance, role changes, access revocation, theme persistence, password/session invalidation, deletion, recurring schedules, private habit check-ins, schedule conflicts, and prototype import.');
 if(/Fatal error|Uncaught|Warning:/.test(logs))throw Error(logs);
 }finally{if(server){server.kill();await new Promise(r=>server.once('exit',r))}fixture('drop');fs.rmSync(runtime,{recursive:true,force:true})}})().catch(e=>{console.error(e);process.exitCode=1});








