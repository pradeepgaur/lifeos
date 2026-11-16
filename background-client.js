async function prepareBoardImage(file){
 if(!file)throw Error('Choose an image first.');
 if(!['image/jpeg','image/png','image/webp'].includes(file.type))throw Error('Choose a JPEG, PNG, or WebP image.');
 if(file.size>20*1024*1024)throw Error('Choose a source image smaller than 20 MB.');
 if(file.size<=384*1024)return file;
 const url=URL.createObjectURL(file),image=new Image();
 try{
  await new Promise((resolve,reject)=>{image.onload=resolve;image.onerror=()=>reject(Error('The browser could not open this image. Try exporting it as JPEG.'));image.src=url});
  const canvas=document.createElement('canvas'),ctx=canvas.getContext('2d');if(!ctx)throw Error('This browser cannot prepare the image. Try a smaller JPEG.');
  let scale=Math.min(1,1920/Math.max(image.naturalWidth,image.naturalHeight)),blob;
  for(let attempt=0;attempt<7;attempt++){
   canvas.width=Math.max(1,Math.round(image.naturalWidth*scale));canvas.height=Math.max(1,Math.round(image.naturalHeight*scale));ctx.fillStyle='#f0f2f5';ctx.fillRect(0,0,canvas.width,canvas.height);ctx.drawImage(image,0,0,canvas.width,canvas.height);
   blob=await new Promise(resolve=>canvas.toBlob(resolve,'image/jpeg',Math.max(.6,.84-attempt*.04)));
   if(blob&&blob.size<=384*1024)return new File([blob],'board-background.jpg',{type:'image/jpeg'});scale*=.78;
  }
  throw Error('Could not optimize this image. Try a smaller JPEG.');
 }finally{URL.revokeObjectURL(url)}
}
async function saveBoardBackground(remove=false){
 const error=$('backgroundError');error.hidden=true;$('backgroundSave').disabled=true;$('backgroundRemove').disabled=true;
 try{
  const form=new FormData();form.append('board',board);form.append('version',String(settingsVersion));
  if(remove)form.append('remove','1');else{$('backgroundSave').textContent='Preparing image…';const file=await prepareBoardImage($('backgroundFile').files[0]);form.append('image',file)}
  $('backgroundSave').textContent='Saving…';const response=await fetch('background.php',{method:'POST',headers:{'X-CSRF-Token':csrf},body:form});
  let result;try{result=await response.json()}catch{throw Error('The host returned HTTP '+response.status+' instead of an upload response. Check its upload or security limits.');}
  if(!response.ok)throw Error(result.error||'Could not save background.');
  await refresh(true);settingsRender();setView(view);toast(remove?'Background removed.':'Board background saved.');
 }catch(e){error.textContent=e.message||'Upload failed. Check your connection and try again.';error.hidden=false;toast(error.textContent)}
 finally{$('backgroundSave').textContent='Upload background';$('backgroundSave').disabled=false;$('backgroundRemove').disabled=false}
}
