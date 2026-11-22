const fs=require('fs'),path=require('path'),assert=require('assert/strict'),crypto=require('crypto'),{spawn}=require('child_process');
const root=path.resolve(__dirname,'..'),runtime=path.join(__dirname,'.runtime','landing_'+crypto.randomBytes(6).toString('hex')),php='C:/xampp/php/php.exe',port=19200+crypto.randomInt(500),base=`http://127.0.0.1:${port}/landing_page/`;
fs.mkdirSync(runtime,{recursive:true});let server;
(async()=>{try{
 server=spawn(php,['-d','session.save_path='+runtime,'-S',`127.0.0.1:${port}`,'-t',root],{cwd:root,env:{...process.env,LIFEOS_LANDING_STORAGE:runtime},stdio:['ignore','ignore','pipe']});let logs='';server.stderr.on('data',d=>logs+=d);
 for(let i=0;i<40;i++){try{await fetch(base+'lavender.php');break}catch{await new Promise(r=>setTimeout(r,100))}}
 for(const page of ['lavender.php','privacy.php','landing.css','landing.js']){const r=await fetch(base+page);assert.equal(r.status,200,page);const text=await r.text();assert(!text.includes('Warning:'),page);if(page.match(/^lavender/)){for(const field of ['name','email','useCase','people','timezone','consent'])assert(text.includes(`name="${field}"`),field);assert(text.includes('Request my invitation'));for(const anchor of ['product','how','request','questions'])assert(text.includes(`id="${anchor}"`));assert(!/https?:\/\//.test(text),'Landing pages should not load remote assets');}}
 const tokenResponse=await fetch(base+'request.php'),cookie=tokenResponse.headers.getSetCookie().map(c=>c.split(';')[0]).join('; '),token=(await tokenResponse.json()).csrf;
 const payload={name:'Landing Test',email:'landing@example.test',useCase:'Personal goals and habits',people:'Just me',timezone:'Asia/Kolkata',message:'Test only',variant:'lavender',consent:'yes'};
 async function post(data=payload,csrf=token){const r=await fetch(base+'request.php',{method:'POST',headers:{Cookie:cookie,'Content-Type':'application/json','X-CSRF-Token':csrf},body:JSON.stringify(data)});return {status:r.status,...await r.json()}}
 assert.equal((await post(payload,'bad')).status,419);assert.equal((await post({...payload,email:'bad'})).status,400);assert.equal((await post({...payload,consent:''})).status,400);assert.equal((await post({...payload,useCase:'unknown'})).status,400);
 assert.equal((await post({...payload,website:'spam'})).status,200);assert(!fs.existsSync(path.join(runtime,'requests.jsonl')));
 for(const variant of ['lavender','lavender','lavender'])assert.equal((await post({...payload,variant})).status,200);
 const records=fs.readFileSync(path.join(runtime,'requests.jsonl'),'utf8').trim().split('\n').map(JSON.parse);assert.equal(records.length,3);assert.deepEqual(records.map(r=>r.design),['lavender','lavender','lavender']);assert(records.every(r=>r.consent&&r.email==='landing@example.test'&&r.status==='new'));assert(records.every(r=>!('password' in r)&&!('ip' in r)));
 assert.equal((await post()).status,200);assert.equal((await post()).status,200);assert.equal((await post()).status,429);
 assert.equal((await fetch(base+'page.php')).status,404);
 assert(!/Fatal error|Uncaught|Warning:/.test(logs),logs);
 console.log('PASS: Lavender landing page and assets, signup fields, private request persistence, CSRF, consent/email/use-case validation, honeypot, rate limiting, and direct-template protection.');
 }finally{if(server){server.kill();await new Promise(r=>server.once('exit',r))}fs.rmSync(runtime,{recursive:true,force:true})}})().catch(e=>{console.error(e);process.exitCode=1});
