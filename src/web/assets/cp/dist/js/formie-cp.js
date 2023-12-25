typeof Craft.Formie=="undefined"&&(Craft.Formie={});Craft.Formie.SubmissionIndex=Craft.BaseElementIndex.extend({editableForms:null,$newSubmissionBtnGroup:null,$newSubmissionBtn:null,startDate:null,endDate:null,init(t,e,i){this.on("selectSource",$.proxy(this,"updateButton")),this.on("selectSite",$.proxy(this,"updateButton")),i.criteria={isIncomplete:!1,isSpam:!1},e.find(".main");var n=e.find("#toolbar:first"),a=n.find(".statusmenubtn:first"),r=a.menubtn().data("menubtn");if(r){var f=$('<li><a data-incomplete><span class="icon" data-icon="draft"></span> '+Craft.t("formie","Incomplete")+"</a></li>"),s=$('<li><a data-spam><span class="icon" data-icon="error"></span> '+Craft.t("formie","Spam")+"</a></li>"),d=$('<hr class="padded">');r.menu.addOptions(f.children()),r.menu.addOptions(s.children()),d.appendTo(r.menu.$container.find("ul:first")),f.appendTo(r.menu.$container.find("ul:first")),s.appendTo(r.menu.$container.find("ul:first")),r.menu.on("optionselect",$.proxy(this,"_handleStatusChange"))}Craft.ui.createDateRangePicker({onChange:function(l,u){this.startDate=l,this.endDate=u,this.updateElements()}.bind(this)}).appendTo(n),this.base(t,e,i)},afterInit(){this.editableForms=[];var{editableSubmissions:t}=Craft.Formie;if(t)for(var e=0;e<t.length;e++){var i=t[e];this.getSourceByKey("form:"+i.id)&&this.editableForms.push(i)}this.base()},_handleStatusChange(t){this.statusMenu.$options.removeClass("sel");var e=$(t.selectedOption).addClass("sel");this.$statusMenuBtn.html(e.html()),this.trashed=!1,this.drafts=!1,this.status=null,this.settings.criteria.isIncomplete=!1,this.settings.criteria.isSpam=!1;let i=null;Garnish.hasAttr(e,"data-spam")?(this.settings.criteria.isSpam=!0,i="spam"):Garnish.hasAttr(e,"data-incomplete")?(this.settings.criteria.isIncomplete=!0,i="incomplete"):Garnish.hasAttr(e,"data-trashed")?(this.trashed=!0,this.settings.criteria.isIncomplete=null,this.settings.criteria.isSpam=null,i="trashed"):Garnish.hasAttr(e,"data-drafts")?(this.drafts=!0,i="drafts"):this.status=e.data("status"),this.activeViewMenu&&this.activeViewMenu.updateSortField(),Craft.setQueryParam("status",i),this.updateElements()},getViewClass(t){return t==="table"?Craft.Formie.SubmissionTableView:this.base(t)},getDefaultSort(){return["dateCreated","desc"]},getDefaultSourceKey(){if(this.settings.context==="index"&&typeof defaultFormieFormHandle!="undefined")for(var t=0;t<this.$sources.length;t++){var e=$(this.$sources[t]);if(e.data("handle")===defaultFormieFormHandle)return e.data("key")}return this.base()},updateButton(){if(!!this.$source){var t=this.$source.data("handle"),e,i,n;if(this.editableForms.length){this.$newSubmissionBtnGroup&&this.$newSubmissionBtnGroup.remove();var a;if(t){for(e=0;e<this.editableForms.length;e++)if(this.editableForms[e].handle===t){a=this.editableForms[e];break}}this.$newSubmissionBtnGroup=$('<div class="btngroup submit"/>');var r;if(a?(i=this._getFormTriggerHref(a),n=this.settings.context==="index"?Craft.t("formie","New submission"):Craft.t("formie","New {form} submission",{form:a.name}),this.$newSubmissionBtn=$('<a class="btn submit add icon" '+i+' role="button" tabindex="0">'+Craft.escapeHtml(n)+"</a>").appendTo(this.$newSubmissionBtnGroup),this.settings.context!=="index"&&this.addListener(this.$newSubmissionBtn,"click",function(u){this._openCreateSubmissionModal(u.currentTarget.getAttribute("data-id"))}),this.editableForms.length>1&&(r=$("<button/>",{type:"button",class:"btn submit menubtn"}).appendTo(this.$newSubmissionBtnGroup))):this.$newSubmissionBtn=r=$("<button/>",{type:"button",class:"btn submit add icon menubtn",text:Craft.t("formie","New submission")}).appendTo(this.$newSubmissionBtnGroup),r){var f='<div class="menu"><ul>';for(e=0;e<this.editableForms.length;e++){var s=this.editableForms[e];(this.settings.context==="index"&&$.inArray(this.siteId,s.sites)!==-1||this.settings.context!=="index"&&s!==a)&&(i=this._getFormTriggerHref(s),n=this.settings.context==="index"?s.name:Craft.t("formie","New {form} submission",{form:s.name}),f+="<li><a "+i+">"+Craft.escapeHtml(n)+"</a></li>")}f+="</ul></div>",$(f).appendTo(this.$newSubmissionBtnGroup);var d=new Garnish.MenuBtn(r);this.settings.context!=="index"&&d.on("optionSelect",u=>{this._openCreateSubmissionModal(u.option.getAttribute("data-id"))})}this.addButton(this.$newSubmissionBtnGroup)}if(this.settings.context==="index"&&typeof history!="undefined"){var l="formie/submissions";t&&(l+="/"+t),history.replaceState({},"",Craft.getUrl(l))}}},getViewParams:function(){var t=this.base();if(this.startDate||this.endDate){var e=this.$source.data("date-attr")||"dateCreated";t.criteria[e]=["and"],this.startDate&&t.criteria[e].push(">="+this.startDate.getTime()/1e3),this.endDate&&t.criteria[e].push("<"+(this.endDate.getTime()/1e3+86400))}return t},getSite(){if(!!this.siteId)return Craft.sites.find(t=>t.id==this.siteId)},_getFormTriggerHref(t){if(this.settings.context==="index"){const e=`formie/submissions/${t.handle}/new`,i=this.getSite(),n=i?{site:i.handle}:void 0;return`href="${Craft.getUrl(e,n)}"`}return`data-id="${t.id}"`},_openCreateSubmissionModal(t){if(!this.$newSubmissionBtn.hasClass("loading")){for(var e,i=0;i<this.editableForms.length;i++)if(this.editableForms[i].id==t){e=this.editableForms[i];break}if(!!e){this.$newSubmissionBtn.addClass("inactive");var n=this.$newSubmissionBtn.text();this.$newSubmissionBtn.text(Craft.t("formie","New {form} submission",{form:e.name})),Craft.createElementEditor(this.elementType,{hudTrigger:this.$newSubmissionBtnGroup,siteId:this.siteId,attributes:{formId:t},onHideHud:()=>{this.$newSubmissionBtn.removeClass("inactive").text(n)},onSaveElement:a=>{var r="form:"+e.id;this.sourceKey!==r&&this.selectSourceByKey(r),this.selectElementAfterUpdate(a.id),this.updateElements()}})}}}});Craft.Formie.SubmissionTableView=Craft.TableElementIndexView.extend({afterInit(){this.$explorerContainer=$('<div class="chart-explorer-container"></div>').prependTo(this.$container),this.$chartExplorer=$('<div class="chart-explorer"></div>').appendTo(this.$explorerContainer),this.$chartContainer=$('<div class="chart-container"></div>').appendTo(this.$chartExplorer),this.$chart=$('<div class="chart"></div>').appendTo(this.$chartContainer),this.loadReport(),this.base()},groupAndFillData(t){const e=Object.entries(t),i=new Date(e[0][0]),n=new Date(e[e.length-1][0]),a=(i-n)/(1e3*60*60*24);let r;a>=730?r="year":a>=60?r="month":a>=2?r="day":r="hour";const f=u=>{var o=new Date(u.getTime());return r==="year"?(o.setMonth(0),o.setDate(1),o.setHours(0),o.setMinutes(0),o.setSeconds(0)):r==="month"?(o.setDate(1),o.setHours(0),o.setMinutes(0),o.setSeconds(0)):r==="day"?(o.setHours(0),o.setMinutes(0),o.setSeconds(0)):r==="hour"&&(o.setMinutes(0),o.setSeconds(0)),r==="hour"?o.toISOString().slice(0,19).replace("T"," "):o.toISOString().split("T")[0]},s={};let d=new Date(n);for(;d<=i||Object.keys(s).length<2;){const u=f(d);s[u]=0,r==="year"?d.setFullYear(d.getFullYear()+1):r==="month"?d.setMonth(d.getMonth()+1):r==="day"?d.setDate(d.getDate()+1):d.setHours(d.getHours()+1)}for(const[u,o]of e){var l=f(new Date(u));l in s&&(s[l]+=o)}return{data:Object.entries(s).map(([u,o])=>[u,o]),group:r}},loadReport(){const t=$(this.elementIndex.$elements).find(".element");if(!t.length){this.$explorerContainer.addClass("chart-empty");return}this.chart||(this.chart=new Craft.charts.Area(this.$chart));let e={};t.each(function(s,d){let l=$(d).data("date-created");e[l]||(e[l]=0),e[l]++});const i=this.groupAndFillData(e);var a={columns:[{type:i.group==="hour"?"datetime":"date",label:"Date"},{type:"number",label:"Submissions"}],rows:i.data},r=new Craft.charts.DataTable(a),f={orientation:Craft.orientation,formats:{numberFormat:",.0f"},dataScale:i.group};this.chart.draw(r,f)}});(function(t){t(document).on("click",".js-fui-submission-modal-send-btn",function(e){e.preventDefault(),new Craft.Formie.SendNotificationModal(t(this).data("id"))})})(jQuery);Craft.Formie.SendNotificationModal=Garnish.Modal.extend({init(t){this.$form=$('<form class="modal fui-send-notification-modal" method="post" accept-charset="UTF-8"/>').appendTo(Garnish.$bod),this.$body=$('<div class="body"><div class="spinner big"></div></div>').appendTo(this.$form);var e=$('<div class="footer"/>').appendTo(this.$form),i=$('<div class="buttons right"/>').appendTo(e);this.$cancelBtn=$('<input type="button" class="btn" value="'+Craft.t("formie","Cancel")+'"/>').appendTo(i),this.$updateBtn=$('<input type="submit" class="btn submit" value="'+Craft.t("formie","Send Email Notification")+'"/>').appendTo(i),this.$footerSpinner=$('<div class="spinner right hidden"/>').appendTo(e),Craft.initUiElements(this.$form),this.addListener(this.$cancelBtn,"click","onFadeOut"),this.addListener(this.$updateBtn,"click","onSend"),this.base(this.$form);var n={id:t};Craft.sendActionRequest("POST","formie/submissions/get-send-notification-modal-content",{data:n}).then(a=>{this.$body.html(a.data.modalHtml),Craft.appendHeadHtml(a.data.headHtml),Craft.appendBodyHtml(a.data.footHtml)})},onFadeOut(){this.$form.remove(),this.$shade.remove()},onSend(t){t.preventDefault(),this.$footerSpinner.removeClass("hidden");var e=this.$form.serialize();Craft.sendActionRequest("POST","formie/submissions/send-notification",{data:e}).then(i=>{location.reload()}).catch(({response:i})=>{i&&i.data&&i.data.message?Craft.cp.displayError(i.data.message):Craft.cp.displayError()}).finally(()=>{this.$footerSpinner.addClass("hidden")})}});Craft.registerElementIndexClass("verbb\\formie\\elements\\Submission",Craft.Formie.SubmissionIndex);typeof Craft.Formie=="undefined"&&(Craft.Formie={});(function(t){t(document).on("click",".js-fui-notification-modal-resend-btn",function(e){e.preventDefault(),new Craft.Formie.ResendNotificationModal(t(this).data("id"))})})(jQuery);Craft.Formie.ResendNotificationModal=Garnish.Modal.extend({init(t){this.$form=$('<form class="modal fui-resend-modal" method="post" accept-charset="UTF-8"/>').appendTo(Garnish.$bod),this.$body=$('<div class="body"><div class="spinner big"></div></div>').appendTo(this.$form);var e=$('<div class="footer"/>').appendTo(this.$form),i=$('<div class="buttons right"/>').appendTo(e);this.$cancelBtn=$('<input type="button" class="btn" value="'+Craft.t("formie","Cancel")+'"/>').appendTo(i),this.$updateBtn=$('<input type="submit" class="btn submit" value="'+Craft.t("formie","Resend Email Notification")+'"/>').appendTo(i),this.$footerSpinner=$('<div class="spinner right hidden"/>').appendTo(e),Craft.initUiElements(this.$form),this.addListener(this.$cancelBtn,"click","onFadeOut"),this.addListener(this.$updateBtn,"click","onResend"),this.base(this.$form);var n={id:t};Craft.sendActionRequest("POST","formie/sent-notifications/get-resend-modal-content",{data:n}).then(a=>{this.$body.html(a.data.modalHtml),Craft.appendHeadHtml(a.data.headHtml),Craft.appendBodyHtml(a.data.footHtml)})},onFadeOut(){this.$form.remove(),this.$shade.remove()},onResend(t){t.preventDefault(),this.$footerSpinner.removeClass("hidden");var e=this.$form.serialize();Craft.sendActionRequest("POST","formie/sent-notifications/resend",{data:e}).then(i=>{location.reload()}).catch(({response:i})=>{i&&i.data&&i.data.message?Craft.cp.displayError(i.data.message):Craft.cp.displayError()}).finally(()=>{this.$footerSpinner.addClass("hidden")})}});Craft.Formie.BulkResendElementAction=Garnish.Base.extend({init(t){new Craft.ElementActionTrigger({type:t,batch:!0,activate(e){new Craft.Formie.BulkResendModal(e.find(".element"),e)}})}});Craft.Formie.BulkResendModal=Garnish.Modal.extend({init(t,e){this.$element=t,this.$selectedItems=e;var i=e.length==1?"":"s",n="<strong>"+e.length+"</strong> notification"+i;this.$form=$('<form class="modal fitted" method="post" accept-charset="UTF-8"/>').appendTo(Garnish.$bod),this.$body=$('<div class="body" style="max-width: 560px;"><h2>'+Craft.t("formie","Bulk Resend Email Notification")+"</h2><p>"+Craft.t("formie","You are about to resend {desc}. You can resend each notification to their original recipients, or choose specific recipients.",{desc:n})+"</p></div>").appendTo(this.$form);var a=Craft.ui.createSelectField({label:Craft.t("formie","Recipients"),name:"recipientsType",options:[{label:Craft.t("formie","Original Recipients"),value:"original"},{label:Craft.t("formie","Custom Recipients"),value:"custom"}],toggle:!0,targetPrefix:"type-"}).appendTo(this.$body),r=$("<div/>",{id:"type-custom",class:"hidden"}).appendTo(this.$body);Craft.ui.createTextField({label:Craft.t("formie","Custom Recipients"),instructions:Craft.t("formie","Provide recipients for each email notification to be sent to. For multiple recipients, separate each with a comma."),name:"to",required:!0}).appendTo(r),this.$selectedItems.each((d,l)=>{$("<input/>",{type:"hidden",name:"ids[]",value:$(l).data("id")}).appendTo(this.$body)});var f=$('<div class="footer"/>').appendTo(this.$form),s=$('<div class="buttons right"/>').appendTo(f);this.$cancelBtn=$('<input type="button" class="btn" value="'+Craft.t("formie","Cancel")+'"/>').appendTo(s),this.$updateBtn=$('<input type="submit" class="btn submit" value="'+Craft.t("formie","Resend Email Notifications")+'"/>').appendTo(s),this.$footerSpinner=$('<div class="spinner right hidden"/>').appendTo(f),this.addListener(this.$cancelBtn,"click","onFadeOut"),this.addListener(this.$updateBtn,"click","onResend"),this.addListener(a,"change","onSelectChange"),this.base(this.$form)},onSelectChange(){this.updateSizeAndPosition()},onFadeOut(){this.$form.remove(),this.$shade.remove()},onResend(t){t.preventDefault(),this.$footerSpinner.removeClass("hidden");var e=this.$form.serialize();Craft.sendActionRequest("POST","formie/sent-notifications/bulk-resend",{data:e}).then(i=>{location.reload()}).catch(({response:i})=>{i&&i.data&&i.data.message?Craft.cp.displayError(i.data.message):Craft.cp.displayError()}).finally(()=>{this.$footerSpinner.addClass("hidden")})}});typeof Craft.Formie=="undefined"&&(Craft.Formie={});(function(t){})(jQuery);
//# sourceMappingURL=formie-cp.js.map
