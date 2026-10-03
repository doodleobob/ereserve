(() => {
    const tableSelector=document.querySelector('[data-payment-table]')?'[data-payment-table]':document.querySelector('[data-official-use-table]')?'[data-official-use-table]':'[data-reservation-table]';
    if (!document.querySelector(tableSelector)) return;
    const recordLabel=tableSelector==='[data-payment-table]'?'Payment':tableSelector==='[data-official-use-table]'?'Official Use':'Reservation';
    const modals=window.EReserveModal;
    let navigation, sequence=0, downloading=false;
    const toast=modals.toast;
    const refresh=async(url=window.location.href,push=false)=>{
        navigation?.abort();navigation=new AbortController();const current=++sequence;
        const root=document.querySelector(tableSelector);root?.setAttribute('aria-busy','true');
        try {
            const response=await fetch(url,{credentials:'same-origin',cache:'no-store',headers:{Accept:'text/html'},signal:navigation.signal});
            if(!response.ok||response.redirected)throw Error('Unable to refresh the table. Check your connection or sign in again.');
            const parsed=new DOMParser().parseFromString(await response.text(),'text/html');
            const next=parsed.querySelector('main'),table=next?.querySelector(tableSelector);
            if(!table)throw Error(`The ${recordLabel} table is unavailable. Refresh the page and sign in again.`);
            if(current!==sequence)return;
            next.querySelectorAll('script').forEach(script=>script.remove());
            document.querySelector('main').replaceWith(next);
            const location=new URL(url,window.location.href);
            if(location.searchParams.has('page'))location.searchParams.set('page',table.dataset.page);
            window.history[push?'pushState':'replaceState']({},'',location);
            initializeDialogs();document.dispatchEvent(new Event('reservations:updated'));
        } finally {if(current===sequence)document.querySelector(tableSelector)?.removeAttribute('aria-busy');}
    };
    const editStep=(dialog,step='choose')=>{
        if(!dialog.hasAttribute('data-accepted-edit'))return;
        dialog.querySelectorAll('[data-edit-step]').forEach(panel=>{
            panel.hidden=panel.dataset.editStep!==step;
            if(panel.tagName==='FIELDSET')panel.disabled=panel.hidden;
        });
        dialog.querySelector('[name="action"]').value=step==='choose'?'':step;
        const error=dialog.querySelector('[data-action-error]');if(error)error.hidden=true;
    };
    const initializeDialogs=()=>document.querySelectorAll(`${tableSelector} dialog`).forEach(dialog=>{
        if(dialog.hasAttribute('data-accepted-edit'))editStep(dialog);
        dialog.addEventListener('modal:reset',()=>editStep(dialog));
    });
    initializeDialogs();
    const filterUrl=form=>{
        const url=new URL(form.action,window.location.href);url.search=new URLSearchParams(new FormData(form));url.searchParams.delete('page');return url;
    };
    document.addEventListener('change',event=>{
        const control=event.target;
        if(!control.closest(tableSelector)||(!control.matches('[data-auto-submit]')&&!control.matches('[data-table-filter]')))return;
        if(modals.processing)return;
        refresh(filterUrl(control.form),true).catch(error=>{if(error.name!=='AbortError')toast(error.message,true);});
    });
    document.addEventListener('click',event=>{
        const download=event.target.closest('[data-payment-download]');
        if(download){
            event.preventDefault();if(downloading||modals.processing||document.querySelector(tableSelector)?.hasAttribute('aria-busy'))return;
            downloading=true;
            const menu=download.closest('.payment-download');menu?.setAttribute('aria-busy','true');
            (async()=>{try {
                const response=await fetch(download.href,{credentials:'same-origin',cache:'no-store',headers:{Accept:'application/octet-stream','X-Requested-With':'XMLHttpRequest'}});
                if(!response.ok||response.redirected){const data=await response.json().catch(()=>({}));throw Error(Object.values(data.errors||{}).flat().join(' ')||data.message||'Unable to download the payment report.');}
                const blob=await response.blob();
                if(!blob.size||!['application/pdf','application/vnd.openxmlformats-officedocument.spreadsheetml.sheet','text/csv'].some(type=>blob.type.startsWith(type)))throw Error('The payment report is unavailable. Sign in again and retry.');
                const url=URL.createObjectURL(blob),link=document.createElement('a');
                const filename=response.headers.get('Content-Disposition')?.match(/filename="?([^";]+)"?/i)?.[1];
                link.href=url;link.download=filename||'ereserve-payment-report';document.body.appendChild(link);link.click();link.remove();setTimeout(()=>URL.revokeObjectURL(url),1000);
                if(menu)menu.open=false;
            }catch(error){toast(error.message,true);}finally{downloading=false;menu?.removeAttribute('aria-busy');}})();return;
        }
        const choice=event.target.closest('[data-edit-choice]');
        if(choice&&!modals.processing){editStep(choice.closest('dialog'),choice.dataset.editChoice);return;}
        const link=event.target.closest('[data-table-link]');
        if(link&&!event.ctrlKey&&!event.metaKey&&!event.shiftKey&&!event.altKey&&event.button===0){
            event.preventDefault();if(modals.processing)return;refresh(link.href,true).catch(error=>{if(error.name!=='AbortError')toast(error.message,true);});return;
        }
    });
    window.addEventListener('popstate',()=>refresh(window.location.href).catch(error=>{if(error.name!=='AbortError')toast(error.message,true);}));
    document.addEventListener('submit',async event=>{
        const form=event.target;
        if(form.id==='reservation-filters'||form.id==='official-use-filters'||form.id==='payment-filters'){
            event.preventDefault();if(modals.processing)return;refresh(filterUrl(form),true).catch(error=>{if(error.name!=='AbortError')toast(error.message,true);});return;
        }
        if(!form.matches('[data-reservation-action]'))return;
        event.preventDefault();if(modals.processing)return;
        navigation?.abort();sequence++;
        await modals.submit(form,{refresh,label:recordLabel});
    });
})();
