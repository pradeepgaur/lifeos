const fs=require('fs'),path=require('path'),assert=require('assert/strict'),{spawn,spawnSync}=require('child_process'),crypto=require('crypto');
const root=path.resolve(__dirname,'..'),php=process.env.LIFEOS_PHP||'C:/xampp/php/php.exe',name='lifeos_test_'+crypto.randomBytes(6).toString('hex'),runtime=path.join(__dirname,'.runtime',name),port=18700+crypto.randomInt(200),base=`http://127.0.0.1:${port}/`;
fs.mkdirSync(runtime,{recursive:true});let server;
function fixture(action,...args){const r=spawnSync(php,[path.join(__dirname,'db-fixture.php'),action,name,...args],{encoding:'utf8'});if(r.status!==0)throw Error(r.stderr||r.stdout||'DB fixture failed')}
function removeSessionFile(client){const pair=client.cookie.split('; ').find(s=>/^lifeos_[a-f0-9]{12}=/.test(s));assert(pair,'PHP session cookie expected');const id=pair.slice(pair.indexOf('=')+1);assert(/^[a-zA-Z0-9,-]+$/.test(id));const target=path.join(runtime,'sess_'+id);assert.equal(path.dirname(target),runtime);if(fs.existsSync(target))fs.unlinkSync(target)}
class Client{
 constructor(){this.cookie='';this.csrf='';this.lastCookies=[]}
 async request(url,options={}){const r=await fetch(base+url,{...options,redirect:'manual',headers:{...options.headers,Cookie:this.cookie}});this.lastCookies=r.headers.getSetCookie();for(const c of this.lastCookies){const pair=c.split(';')[0];const key=pair.split('=')[0];this.cookie=this.cookie.split('; ').filter(x=>x&&!x.startsWith(key+'=')).concat(pair).join('; ')}return r}
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
 assert(owner.lastCookies.some(c=>c.includes('_login=')&&/Max-Age=604800/i.test(c)&&/HttpOnly/i.test(c)&&/SameSite=Lax/i.test(c)),'Sign-in must persist for seven days');
 await owner.session();assert.equal((await anon.get('state')).status,401);
 fixture('age-logins');removeSessionFile(owner);const oldCsrf=owner.csrf;
 await owner.post('profile_update',{name:'Owner'});
 assert(owner.lastCookies.some(c=>c.includes('_login=')&&/Max-Age=604800/i.test(c)),'Interaction after inactivity must renew the seven-day window');
 assert.equal((await owner.session()).user.email,'owner@example.test');assert.equal(owner.csrf,oldCsrf,'Recovering after host cleanup must preserve the CSRF token');
 const tokenOnly=new Client();tokenOnly.cookie=owner.cookie.split('; ').filter(s=>!/^lifeos_[a-f0-9]{12}=/.test(s)).join('; ');const cookieParts=tokenOnly.cookie.split('=');const last=cookieParts[1].slice(-1);tokenOnly.cookie=tokenOnly.cookie.slice(0,-1)+(last==='a'?'b':'a');assert.equal((await tokenOnly.session()).user,null,'A modified remember-me secret must fail');
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
 await owner.post('task_update',{...t,startTime:'09:00',endTime:'10:30',version:(await owner.get('state')).tasks.find(x=>x.id===task).version});
 s=await owner.get('state');assert.equal(s.tasks.find(x=>x.id===task).startTime,'09:00');assert.equal(s.tasks.find(x=>x.id===task).endTime,'10:30');
 for(const times of [{startTime:'09:00'},{startTime:'11:00',endTime:'10:00'},{startTime:'25:00',endTime:'26:00'},{startTime:'09:00',endTime:'10:00',due:''}])await owner.post('task_create',{board:bid,status:'todo',title:'Invalid timed task',due:'2026-11-20',...times},400);
 await require('./task-order.cjs')({owner,bid,unrelatedTask:task,fixture});
 const other=(await owner.post('board_create',{name:'Second board'})).id;
 await owner.post('task_create',{board:other,status:'todo',title:'Wrong goal',goal},400);
 await owner.post('board_goals',{board:other,goals:[goal]});
 s=await owner.get('state');assert.deepEqual(s.goals.find(g=>g.id===goal).boards,[bid,other]);
 const sharedTask=(await owner.post('task_create',{board:other,status:'todo',title:'Shared goal task',goal})).id;
 await owner.post('board_goals',{board:other,goals:[]},409);
 const unlinked=(await owner.post('goal_create',{name:'Independent goal'})).id;
 assert.deepEqual((await owner.get('state')).goals.find(g=>g.id===unlinked).boards,[]);
 await owner.post('goal_update',{id:unlinked,version:1,name:'Independent goal',achieved:true});
 assert((await owner.get('state')).goals.find(g=>g.id===unlinked).achievedAt);
 const col=(await owner.post('column_create',{board:bid,name:'Ready',done:true})).id;
 s=await owner.get('state');let order=s.columns[bid].map(c=>c.id).reverse();await owner.post('column_reorder',{board:bid,order});s=await owner.get('state');assert.equal(s.columns[bid][0].id,col);
 t=s.tasks.find(t=>t.id===task);await owner.post('task_move',{id:task,status:col,version:t.version});
 let token=(await owner.post('invite_create',{board:bid,email:'friend@example.test',role:'reviewer'})).token;
 assert.equal((await anon.get('invitation&token='+token)).invitation.role,'reviewer');
 const friend=new Client();await friend.session();await friend.post('register',{name:'Friend',email:'wrong@example.test',password,token},403);await friend.post('register',{name:'Friend',email:'friend@example.test',password,token});
 let f=await friend.get('state');assert.equal(f.schedule.length,0);assert.equal(f.habitChecks.length,0);await friend.post('habit_check',{id:routine,day:'2026-11-14',checked:true},404);await friend.post('schedule_update',{id:routine,version:1,title:'Denied'},404);const personalRoutine=(await friend.post('schedule_create',{title:'My private habit',days:[1,2,3,4,5,6,7],startTime:'10:00',endTime:'10:30',startDate:'2026-01-01',trackHabit:true})).id;await friend.post('habit_check',{id:personalRoutine,day:'2026-11-14',checked:true});assert.equal((await friend.get('state')).habitChecks.length,1);assert.equal((await owner.get('state')).habitChecks.length,1);assert.equal(f.boards.length,1);assert(!f.boards.some(b=>b.id===other));await friend.post('task_create',{board:bid,status:'todo',title:'Unauthorized'},403);await friend.post('goal_create',{board:other,name:'Hidden'},404);await friend.post('goal_update',{id:goal,version:1,name:'Denied'},404);await friend.post('schedule_create',{title:'Foreign goal',days:[1],startTime:'12:00',endTime:'13:00',startDate:'2026-01-01',goal},400);
 await require('./notes.cjs')({owner,friend,anon,bid,task,goal,friendId:f.user.id,fixture});
 const beforeNotesUpgrade=await owner.get('state');fixture('notes-downgrade');const afterNotesUpgrade=await owner.get('state');assert.equal(afterNotesUpgrade.status,200);assert.equal(afterNotesUpgrade.tasks.length,beforeNotesUpgrade.tasks.length);assert.equal(afterNotesUpgrade.goals.length,beforeNotesUpgrade.goals.length);assert.deepEqual(afterNotesUpgrade.notes,[]);
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
 const imported=await owner.post('prototype_import',{data:{boards:['Old board'],columns:{'Old board':{custom:'Custom'}},goals:[{id:'old-goal',name:'Old goal'}],tasks:[{board:'Old board',status:'custom',title:'Old task',goal:'old-goal',checks:[],comments:[],createdAt:'2026-11-01T00:00:00Z'}]}});s=await owner.get('state');const importedTask=s.tasks.find(t=>t.board===imported.board);assert(importedTask);assert.equal(importedTask.createdAt,'2026-11-01T00:00:00Z');assert(importedTask.goal);assert.equal(s.goals.find(g=>g.boards.includes(imported.board)).createdAt,null);
 for(const theme of ["mono","linen","wine","lavender"]){await owner.post('profile_update',{name:'Owner updated',theme});assert.equal((await owner.get('state')).user.theme,theme)}
 const logged=new Client();await logged.session();await logged.post('login',{email:'owner@example.test',password:'wrong'},401);await logged.post('login',{email:'owner@example.test',password});
 await owner.post('password_change',{currentPassword:password,password:'Changed-password-2026'});assert.equal((await logged.get('state')).status,401);
 removeSessionFile(owner);assert.equal((await owner.get('state')).status,200,'Current browser should recover after a password change');
 s=await owner.get('state');const b=s.boards.find(b=>b.id===other);await owner.post('board_delete',{id:other,version:b.version});assert.equal((await friend.get('state')).boards.length,0);assert.equal((await friend.get('state')).schedule.length,1,'Personal routine must survive loss of all boards');assert.equal((await owner.get('state')).goals.some(g=>g.id===unlinked),true);
 t=s.tasks.find(t=>t.id===task);await owner.post('task_delete',{id:task,version:t.version});s=await owner.get('state');assert(!s.tasks.some(t=>t.id===task));
 fixture('drop-backgrounds');
 const imageForm=new FormData();imageForm.append('board',bid);imageForm.append('version',String(s.boards.find(b=>b.id===bid).version));imageForm.append('image',new Blob([Buffer.from('iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAQAAAC1HAwCAAAAC0lEQVR42mP8/x8AAusB9Wl6CBsAAAAASUVORK5CYII=','base64')],{type:'image/png'}),'test.png');
 r=await owner.request('background.php',{method:'POST',headers:{'X-CSRF-Token':owner.csrf},body:imageForm});assert.equal(r.status,200,await r.text());
 r=await owner.request('background.php?board='+bid);assert.equal(r.status,200);assert.equal(r.headers.get('content-type'),'image/png');const etag=r.headers.get('etag');await r.arrayBuffer();
 r=await owner.request('background.php?board='+bid,{headers:{'If-None-Match':etag}});assert.equal(r.status,304);
 r=await friend.request('background.php?board='+bid);assert.equal(r.status,404);r=await anon.request('background.php?board='+bid);assert.equal(r.status,401);
 s=await owner.get('state');assert.equal(Number(s.boards.find(b=>b.id===bid).hasBackground),1);
 const removeForm=new FormData();removeForm.append('board',bid);removeForm.append('version',String(s.boards.find(b=>b.id===bid).version));removeForm.append('remove','1');r=await owner.request('background.php',{method:'POST',headers:{'X-CSRF-Token':owner.csrf},body:removeForm});assert.equal(r.status,200);assert.equal((await owner.request('background.php?board='+bid)).status,404);
 fixture('legacy-workspace');
 s=await owner.get('state');assert.equal(s.status,200,JSON.stringify(s));
 const legacyGoal=s.goals.find(g=>g.name==='Legacy health'),legacyRoutine=s.schedule.find(e=>e.title==='Legacy routine');
 assert(legacyGoal.boards.includes(bid));assert.equal(legacyGoal.owner,s.user.id);assert.equal(legacyRoutine.goal,legacyGoal.id);assert.equal(legacyRoutine.board,'');assert(s.habitChecks.some(c=>c.schedule===legacyRoutine.id&&c.day==='2026-11-15'));
 const migratedFriend=await friend.get('state'),friendLegacy=migratedFriend.schedule.find(e=>e.title==='Legacy routine');assert(friendLegacy);assert.notEqual(friendLegacy.id,legacyRoutine.id);assert.equal(friendLegacy.goal,'');assert(migratedFriend.habitChecks.some(c=>c.schedule===friendLegacy.id&&c.day==='2026-11-15'));
 const migrationRevision=s.revision;assert.equal((await owner.get('state&revision='+migrationRevision)).unchanged,true);
 const temporaryBoard=(await owner.post('board_create',{name:'Delete without losing goals'})).id;await owner.post('board_goals',{board:temporaryBoard,goals:[legacyGoal.id]});
 s=await owner.get('state');await owner.post('board_delete',{id:temporaryBoard,version:s.boards.find(b=>b.id===temporaryBoard).version});s=await owner.get('state');assert(s.goals.some(g=>g.id===legacyGoal.id));assert(s.schedule.some(e=>e.id===legacyRoutine.id));
 console.log('PASS: shared goals across boards, personal routines without boards, private schedule access, achievement, and automatic legacy migration preserving goal links and each user’s check-ins.');
 fixture('task-times-downgrade');s=await owner.get('state');assert.equal(s.status,200);assert(s.tasks.every(t=>t.startTime===''&&t.endTime===''),'Existing all-day tasks must stay all-day after migration');
 fixture('downgrade');assert.equal((await owner.get('state')).schedule.length,0);
 if(process.env.LIFEOS_PREVIEW){console.log('Preview: '+base);await new Promise(resolve=>process.on('SIGINT',resolve))}
 const expiring=new Client();await expiring.session();await expiring.post('login',{email:'owner@example.test',password:'Changed-password-2026'});const selector=expiring.cookie.match(/_login=([a-f0-9]{32})(?::|%3A)/i)[1];fixture('expire-login',selector);removeSessionFile(expiring);assert.equal((await expiring.session()).user,null,'Expired persistent credentials must fail');
 const recoveredCopy=new Client();recoveredCopy.cookie=owner.cookie.split('; ').filter(s=>!/^lifeos_[a-f0-9]{12}=/.test(s)).join('; ');assert((await recoveredCopy.session()).user,'A second recovered PHP session should start valid');
 const replay=new Client();replay.cookie=owner.cookie;
 await owner.post('logout');assert.equal((await owner.get('state')).status,401);assert.equal((await replay.session()).user,null,'Logout must revoke remembered sign-in as well as the PHP session');assert.equal((await recoveredCopy.get('state')).status,401,'Logout must revoke all PHP sessions recovered from the same browser credential');
 console.log('PASS: seven-day cookies, recovery after four idle days/PHP cleanup, CSRF continuity, sliding renewal, tamper rejection, expiry, password-change continuity, and logout replay prevention.');
 console.log('PASS: installer, setup lock, sessions, CSRF, board/column/goal/task CRUD, validation, assignment, timestamps, poll revisions, conflicts, comments, invitation registration/acceptance, role changes, access revocation, theme persistence, password/session invalidation, deletion, recurring schedules, private habit check-ins, schedule conflicts, and prototype import.');
 if(/Fatal error|Uncaught|Warning:/.test(logs))throw Error(logs);
 }finally{if(server){server.kill();await new Promise(r=>server.once('exit',r))}fixture('drop');fs.rmSync(runtime,{recursive:true,force:true})}})().catch(e=>{console.error(e);process.exitCode=1});








