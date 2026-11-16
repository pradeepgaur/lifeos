const fs=require('fs'),path=require('path'),vm=require('vm'),assert=require('assert/strict');
const root=path.resolve(__dirname,'..'),elements=new Map();
function el(id){if(!elements.has(id))elements.set(id,{style:{},value:'',hidden:false,innerHTML:'',textContent:'',dataset:{},classList:{toggle(){},add(){},remove(){}},querySelector(){return el('button')},querySelectorAll(){return []},focus(){}});return elements.get(id)}
const fontProperties={};
const document={documentElement:{style:{setProperty(k,v){fontProperties[k]=v}}},body:{dataset:{},classList:{toggle(){}}},getElementById:el,querySelector:s=>s==='nav b'?el('count'):el(s),querySelectorAll:()=>[]};
const storage=new Map();
const context=vm.createContext({document,window:{},location:{pathname:'/lifeos/index.php'},console,Date,localStorage:{setItem(k,v){storage.set(k,v)},getItem(k){return storage.get(k)||null}},setTimeout(){},clearTimeout(){},structuredClone,Intl,URL,Blob});
for(const file of ['app.js','render.js','timetable.js'])vm.runInContext(fs.readFileSync(path.join(root,file),'utf8').replace(/^\uFEFF/,''),context);
const run=s=>vm.runInContext(s,context);
run(`state={user:{id:'1'},name:'Tester',boards:[{id:'1',name:'Test',role:'owner'}],columns:{'1':{todo:'To do',done:'Done',custom:'Custom <column>'}},columnRows:{'1':[{id:'todo',done:false},{id:'done',done:true},{id:'custom',done:false}]},goals:[],tasks:[{id:'3',board:'1',status:'todo',title:'<Task>',description:'',priority:'High',checks:[],comments:[],due:'2026-11-15',createdAt:'2026-11-01T00:00:00Z',assignee:'1'}],members:[],schedule:[{id:'5',board:'1',title:'Morning <walk>',description:'',days:[1,3,5],startTime:'07:00',endTime:'07:30',startDate:'2026-01-01',endDate:'2026-12-31',trackHabit:true,color:'green'}],habitChecks:[{schedule:'5',day:'2026-11-02'}]};board='1';`);
assert.equal(run(`occurs(state.schedule[0],'2026-11-02')`),true);
assert.equal(run(`occurs(state.schedule[0],'2026-11-03')`),false);
assert.equal(run(`occurs(state.schedule[0],'2027-01-01')`),false);
assert.equal(run(`checkedHabit('5','2026-11-02')`),true);
run("habitAnchor=dateFrom('2026-11-15');habitPeriod='month';renderHabits()");
let html=el('alternate').innerHTML;assert(html.includes('Morning &lt;walk&gt;'));assert(html.includes('2026-11-02'));assert(html.includes('2026-11-30'));assert(!html.includes('2026-11-31'));assert(html.includes(' checked'));
run("calendarAnchor=dateFrom('2026-11-15')");html=run('renderCalendar()');assert(html.includes('Morning &lt;walk&gt;'));assert(html.includes('&lt;Task&gt;'));assert.equal((html.match(/data-schedule="5"/g)||[]).length,13);
html=run('renderList(state.tasks)');assert.equal((html.match(/<table /g)||[]).length,1);assert(html.includes('Custom &lt;column&gt;'));for(const row of html.matchAll(/<tr class="list-column-heading">(.*?)<\/tr>/g))assert(!/data-id|button|tabindex/.test(row[1]));
assert.equal(run('ageLabel(null)'),'Age unknown');assert.equal(run('ageLabel("2026-11-01T00:00:00Z",Date.parse("2026-11-15T00:00:00Z"))'),'14d old');
for(const theme of ['facebook','slate','forest','trello','notion','asana','monday','linear','todoist']){run(`applyTheme('${theme}')`);assert.equal(document.body.dataset.theme,theme)}
const page=fs.readFileSync(path.join(root,'index.html'),'utf8'),stack=[];let ids=new Set();
for(const match of page.matchAll(/<(\/?)([a-z][\w-]*)\b([^>]*)>/gi)){const [,closing,tag,attrs]=match;if(closing){assert.equal(stack.pop()?.tag,tag,'HTML nesting: '+tag);continue}const id=attrs.match(/\bid="([^"]+)"/);if(id){assert(!ids.has(id[1]),'Duplicate ID: '+id[1]);ids.add(id[1])}if(/id="(board|alternate)"/.test(attrs))assert.equal(stack.at(-1)?.attrs,' class="board-section"');if(!['meta','link','input','br','hr','img'].includes(tag))stack.push({tag,attrs})}assert.equal(stack.length,0);
for(const file of ['render.js','timetable.js'])for(const match of fs.readFileSync(path.join(root,file),'utf8').matchAll(/id="([^"${}]+)"/g))ids.add(match[1]);
for(const file of ['app.js','actions.js','timetable.js'])for(const match of fs.readFileSync(path.join(root,file),'utf8').matchAll(/\$\('([^']+)'\)/g))assert(ids.has(match[1]),'Missing DOM ID: '+match[1]);
console.log('PASS: weekly recurrence/date boundaries, monthly habit dates/checks, calendar routines/tasks, escaped names, non-actionable list groups, ages, all themes, HTML nesting, and UI bindings.');
run("view='habits';layout='calendar';board='1';rememberNavigation();view='board';layout='board';board='';restoreNavigation('1')");
assert.equal(run('view'),'habits');assert.equal(run('layout'),'calendar');assert.equal(run('board'),'1');
run("view='today';layout='list';board='2';rememberNavigation();view='board';layout='board';board='';restoreNavigation('1')");
assert.equal(run('view'),'today');assert.equal(run('layout'),'list');assert.equal(run('board'),'2');
run("view='goals';restoreNavigation('another-account')");assert.equal(run('view'),'goals');
storage.set(run("navigationKey('1')"),JSON.stringify({view:'invalid',layout:'invalid',board:'<bad>'}));run("restoreNavigation('1')");assert.equal(run('view'),'board');assert.equal(run('layout'),'board');assert.equal(run('board'),'');
storage.set(run("navigationKey('1')"),'{bad JSON');assert.doesNotThrow(()=>run("restoreNavigation('1')"));
console.log('PASS: navigation restores sidebar page, layout, and board; account-scoped keys and invalid storage are handled safely.');
run('applyFontSize(125)');assert.equal(fontProperties['--font-scale'],'1.25');assert.equal(fontProperties['font-size'],'20px');assert.equal(el('fontSize').value,'125');run('applyFontSize(999)');assert.equal(fontProperties['--font-scale'],'1.12');
const profileSection=page.slice(page.indexOf('<dialog id="profileDialog">'),page.indexOf('<dialog id="goalDialog">'));
assert(profileSection.includes('id="fontSize"'));assert(!page.slice(page.indexOf('<dialog id="settingsDialog">')).includes('id="fontSize"'));
for(const file of ['style.css','themes.css','timetable.css','auth.css']){const css=fs.readFileSync(path.join(root,file),'utf8');assert(!/font-size:\s*\d+(?:\.\d+)?px/.test(css),file+' must use relative font sizes');assert(!/\bfont:[^;{}]*\dpx/.test(css),file+' must scale shorthand fonts too');}
console.log('PASS: proportional font scale, safe default, and relative typography across all stylesheets.');

