// 取得済みの業務結果だけを表示する。実行API・生成モデル・権限の追加は行わない。
(function () {
  'use strict';
  const tr = (value) => window.wp?.i18n?.__(value, 'fourmix-intelligence') || value;
  const labels = {id:'ID',identify:'ID',key:'ID',name:'名前',title:'タイトル',summary:'件名',description:'説明',content:'本文',status:'状態',price:'価格',stock:'在庫',quantity:'数量',updated_at:'更新日時',revision:'更新番号',order_id:'受注ID',product_id:'商品ID',product_class_id:'商品規格ID',customer_id:'会員ID',shipping_id:'配送ID',post_id:'投稿ID'};
  const node = (tag, value, cls) => {const item=document.createElement(tag); if(value!==undefined)item.textContent=String(value); if(cls)item.className=cls; return item;};
  const text = value => value===undefined||value===null?'—':typeof value==='object'?JSON.stringify(value):String(value);
  function body(value, depth=0) {
    if(depth>12||!value||Array.isArray(value)||typeof value!=='object')return value;
    if(value.data!==undefined)return body(value.data,depth+1);
    if(value.structuredContent)return body(value.structuredContent,depth+1);
    if(Array.isArray(value.content)&&value.content.length===1&&value.content[0]?.type==='text')try{return body(JSON.parse(value.content[0].text),depth+1);}catch{}
    return value;
  }
  function rows(value, depth=0) {
    if(depth>12)return null;
    if(Array.isArray(value))return value.filter(row=>row&&typeof row==='object');
    if(!value||typeof value!=='object')return null;
    for(const key of ['items','records','results','projects','issues','products','orders','customers','models','objects','apps','comments','nodes','edges'])if(Array.isArray(value[key]))return value[key].map(row=>row.node||row).filter(row=>row&&typeof row==='object');
    const inner=body(value);return inner!==value?rows(inner,depth+1):value.result!==undefined?rows(value.result,depth+1):null;
  }
  function details(parent,value,depth=0) {
    if(!value||typeof value!=='object'||depth>=8){parent.append(node('p',text(value)));return;}
    const list=node('dl',undefined,'fmi-business__details');
    for(const [key,item] of Object.entries(value)){
      const cell=node('div');cell.append(node('dt',tr(labels[key]||key)));
      const val=node('dd');
      if(item&&typeof item==='object'){const fold=node('details');fold.append(node('summary',tr('詳細を開く')));details(fold,item,depth+1);val.append(fold);}else val.append(node('span',text(item)));
      cell.append(val);list.append(cell);
    }
    parent.append(list);
  }
  function button(label, action) {const item=node('button',tr(label));item.type='button';item.addEventListener('click',action);return item;}
  function table(parent,list,choose,selected,selection){
    const region=node('div',undefined,'fmi-business__scroll');region.tabIndex=0;region.setAttribute('aria-label',tr('全項目の一覧。左右にスクロールできます'));
    const grid=node('table'),head=node('thead'),line=node('tr');
    const fields=[...new Set(list.flatMap(row=>Object.keys(row)))];
    if(selected)line.append(node('th',tr('比較対象')));
    fields.forEach(field=>{const cell=node('th',tr(labels[field]||field));cell.scope='col';line.append(cell);});
    if(choose)line.append(node('th',tr('詳細')));head.append(line);grid.append(head);
    const cells=node('tbody');
    list.forEach(row=>{
      const line=node('tr');
      if(selected){const cell=node('td'),check=node('input');check.type='checkbox';check.checked=selected.has(row);check.disabled=!check.checked&&selected.size>=10;check.setAttribute('aria-label',text(row.title||row.name||row.summary||row.id||row.key||row.order_id||row.product_class_id||row.product_id||row.customer_id||row.shipping_id)+tr('を比較する'));check.addEventListener('change',()=>{check.checked?selected.add(row):selected.delete(row);selection();});cell.append(check);line.append(cell);}
      fields.forEach(field=>{const value=text(row[field]),cell=node('td'),preview=node('span',value.length>240?value.slice(0,240)+'…':value);preview.title=value;cell.append(preview);line.append(cell);});
      if(choose){const cell=node('td');cell.append(button('詳細',()=>choose(row)));line.append(cell);}cells.append(line);
    });grid.append(cells);region.append(grid);parent.append(region);
  }
  function render(parent,result,onDiscuss) {
    const source=result?.data?.workbench||result?.workbench||result?.result?.data?.workbench;
    const views=Array.isArray(source?.views)?source.views.filter(view=>view&&view.origin==='tool_result'&&view.data&&typeof view.data==='object'):[];
    if(!views.length&&!source?.omitted_views)return;
    const root=node('details',undefined,'fmi-business');root.append(node('summary',tr('仕事に使った情報を見る')));
    if(source?.omitted_views)root.append(node('p',tr('保存した結果の一部を表示しています。必要な情報は会話から取得し直してください。')));
    for(const view of views.slice(0,12)){
      const section=node('section'),heading=node('h4',view.title||tr('取得した情報'));section.append(heading);
      if(view.partial)section.append(node('p',tr('一部の情報を表示しています。続きは連携先で確認してください。')));
      const raw=rows(view.data),list=raw?.map(row=>({...row,...(row.data&&typeof row.data==='object'&&!Array.isArray(row.data)?row.data:{})})).map(({data,...row})=>row);
      const controls=node('div',undefined,'fmi-business__controls'),content=node('div'),selected=new Set();
      if(list){
        const input=node('input');input.type='search';input.placeholder=tr('表示中の行を絞り込む');input.setAttribute('aria-label',input.placeholder);
        const count=node('span'),compare=button('選択したデータを比較',()=>{
          content.replaceChildren();const chosen=[...selected];if(chosen.length<2)return;
          content.append(button('一覧へ戻る',draw));table(content,chosen,null);chosen.forEach((row,index)=>{const fold=node('details');fold.append(node('summary',tr('詳細')+' '+(index+1)));details(fold,row);content.append(fold);});
        });
        function draw(){const visible=list.filter(row=>text(row).toLocaleLowerCase().includes(input.value.toLocaleLowerCase()));content.replaceChildren();count.textContent=visible.length+tr('件');compare.disabled=selected.size<2;table(content,visible,row=>{
          content.replaceChildren();content.append(button('一覧へ戻る',draw));
          if(onDiscuss)content.append(button('この内容で相談',()=>onDiscuss({view,row})));details(content,row);
        },selected,()=>{compare.disabled=selected.size<2;content.querySelectorAll('input[type=checkbox]').forEach(check=>{check.disabled=!check.checked&&selected.size>=10;});});}
        input.addEventListener('input',draw);controls.append(input,count,compare);section.append(controls,content);draw();
      }else{details(content,body(view.data));section.append(content);}
      const aggregation=body(view.data)?.aggregation;
      if(aggregation&&list?.every(row=>Number.isFinite(row.value)&&Object.hasOwn(row,'label'))){
        const fold=node('details');fold.append(node('summary',tr('集計グラフ')));const maximum=Math.max(1,...list.map(row=>Math.abs(row.value)));
        list.forEach(row=>{const line=node('div',undefined,'fmi-business__bar'),label=node('span',text(row.label)+'：'+text(row.value)),bar=node('span');bar.style.width=(Math.abs(row.value)/maximum*100)+'%';bar.className=row.value<0?'negative':'';line.append(label,bar);fold.append(line);});section.append(fold);
      }
      root.append(section);
    }
    parent.append(root);
  }
  window.FourmixBusinessResults={render};
})();
