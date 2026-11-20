function toggleSidebar(){ document.querySelector('.sidebar')?.classList.toggle('open'); }
function openModal(id){ document.getElementById(id)?.classList.add('show'); }
function closeModal(id){ document.getElementById(id)?.classList.remove('show'); }

document.addEventListener('click', function(e){
  const backdrop = e.target.closest('.modal-backdrop');
  if(backdrop && e.target === backdrop) backdrop.classList.remove('show');
  if(!e.target.closest('.searchable-select')) document.querySelectorAll('.searchable-select.open').forEach(x=>x.classList.remove('open'));
});
document.addEventListener('keydown', function(e){
  if(e.key==='Escape'){
    document.querySelectorAll('.modal-backdrop.show').forEach(m=>m.classList.remove('show'));
    document.querySelectorAll('.searchable-select.open').forEach(x=>x.classList.remove('open'));
  }
});

function searchableLabel(select){
  const opt=select.options[select.selectedIndex];
  return opt && opt.value ? opt.textContent.trim() : '';
}
function enhanceSearchable(select){
  if(!select || select.dataset.searchableReady==='1') return;
  const wrap=document.createElement('div'); wrap.className='searchable-select';
  select.parentNode.insertBefore(wrap,select); wrap.appendChild(select); select.classList.add('searchable-select-native'); const wasRequired=select.required; select.required=false; select.dataset.searchableReady='1';
  const input=document.createElement('input'); input.type='text'; input.className='searchable-select-input'; input.placeholder=select.options[0]?.textContent.trim()||'Buscar / seleccionar...'; input.autocomplete='off'; input.value=searchableLabel(select); input.disabled=select.disabled; input.required=wasRequired;
  const arrow=document.createElement('span'); arrow.className='material-symbols-outlined searchable-select-arrow'; arrow.textContent='expand_more';
  const list=document.createElement('div'); list.className='searchable-options';
  wrap.appendChild(input); wrap.appendChild(arrow); wrap.appendChild(list);
  function render(filter=''){
    list.innerHTML=''; const f=filter.toLowerCase(); let count=0;
    Array.from(select.options).forEach((opt,i)=>{
      if(!opt.value) return;
      if(f && !opt.textContent.toLowerCase().includes(f)) return;
      const item=document.createElement('div'); item.className='searchable-option'+(i===select.selectedIndex?' active':''); item.textContent=opt.textContent.trim(); item.dataset.index=i; item.dataset.value=opt.value;
      item.addEventListener('mousedown',ev=>{ev.preventDefault();select.selectedIndex=i; input.value=opt.textContent.trim(); wrap.classList.remove('open'); select.dispatchEvent(new Event('change',{bubbles:true}));});
      list.appendChild(item); count++;
    });
    if(!count){const empty=document.createElement('div');empty.className='searchable-empty';empty.textContent='No se encontraron opciones';list.appendChild(empty);}
  }
  input.addEventListener('focus',()=>{if(!input.disabled){input.value='';render('');wrap.classList.add('open');}});
  input.addEventListener('input',()=>{render(input.value);wrap.classList.add('open');});
  input.addEventListener('blur',()=>setTimeout(()=>{input.value=searchableLabel(select);wrap.classList.remove('open');},150));
  wrap.addEventListener('click',()=>{if(!input.disabled) {render('');wrap.classList.add('open');input.focus();}});
  select._searchableSync=()=>{input.value=searchableLabel(select); input.disabled=select.disabled;};
}
function syncSearchableSelect(id){
  const select=document.getElementById(id); if(select?._searchableSync) select._searchableSync();
}

document.addEventListener('DOMContentLoaded',()=>{
  document.querySelectorAll('select.select').forEach(enhanceSearchable);
});


function toggleNotifs(){
  document.getElementById('notifDropdown')?.classList.toggle('show');
}
document.addEventListener('click', function(e){
  if(!e.target.closest('.notif-wrapper')){
    document.getElementById('notifDropdown')?.classList.remove('show');
  }
});
