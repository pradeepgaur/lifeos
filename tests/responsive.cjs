const fs=require('fs'),path=require('path'),vm=require('vm'),assert=require('assert/strict');
const classes=new Set(),listeners={},elements=new Map();let changed;
const document={activeElement:null,body:{classList:{add(c){classes.add(c)},remove(c){classes.delete(c)},contains(c){return classes.has(c)}}},addEventListener(type,fn){listeners[type]=fn},querySelector(){return elements.get('main')}};
function node(id){const n={id,inert:false,hidden:false,attributes:{},setAttribute(k,v){this.attributes[k]=v},focus(){document.activeElement=this},getClientRects(){return [{}]}};elements.set(id,n);return n}
const main=node('main'),sidebar=node('workspaceNav'),toggle=node('navigationToggle'),close=node('navigationClose'),backdrop=node('navigationBackdrop'),last=node('last');sidebar.contains=n=>n===close||n===last;sidebar.querySelectorAll=()=>[close,last];
const media={matches:true,addEventListener(type,fn){changed=fn}};
const context=vm.createContext({document,matchMedia:()=>media,$:id=>elements.get(id)});vm.runInContext(fs.readFileSync(path.join(__dirname,'../responsive.js'),'utf8'),context);vm.runInContext('bindResponsive()',context);
assert(sidebar.inert);assert(!main.inert);assert(backdrop.hidden);toggle.onclick();assert(classes.has('nav-open'));assert(main.inert);assert(!sidebar.inert);assert.equal(document.activeElement,close);assert.equal(toggle.attributes['aria-expanded'],'true');
listeners.keydown({key:'Tab',shiftKey:true,preventDefault(){}});assert.equal(document.activeElement,last);
listeners.keydown({key:'Escape',preventDefault(){}});assert(!classes.has('nav-open'));assert(!main.inert);assert(sidebar.inert);assert.equal(document.activeElement,toggle);
toggle.onclick();listeners.click({target:{closest(){return {}}}});assert(!main.inert);assert(backdrop.hidden);
toggle.onclick();media.matches=false;changed();assert(!main.inert);assert(!sidebar.inert);assert(backdrop.hidden);assert.equal(toggle.attributes['aria-expanded'],'false');
console.log('PASS: mobile drawer, focus trapping, Escape/selection dismissal, backdrop state, and desktop breakpoint recovery.');
