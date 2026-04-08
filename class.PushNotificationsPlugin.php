<?php
/**
 * Push Notifications Plugin - Main Class
 *
 * @author  ChesnoTech
 * @version 1.0.0
 */

require_once 'config.php';

class PushNotificationsPlugin extends Plugin {
    var $config_class = 'PushNotificationsConfig';

    static private $bootstrapped = false;

    function isMultiInstance() {
        return false;
    }

    function bootstrap() {
        if (self::$bootstrapped)
            return;
        self::$bootstrapped = true;

        $pluginDir = dirname(__FILE__) . '/';

        // Signal hooks for push dispatch (all contexts: staff, API, cron)
        require_once $pluginDir . 'class.PushDispatcher.php';
        Signal::connect('ticket.created', array('PushDispatcher', 'onTicketCreated'));
        Signal::connect('object.created', array('PushDispatcher', 'onObjectCreated'));
        Signal::connect('object.edited', array('PushDispatcher', 'onObjectEdited'));
        Signal::connect('model.updated', array('PushDispatcher', 'onModelUpdated'));
        Signal::connect('cron', array('PushDispatcher', 'onCron'));

        // AJAX routes and asset injection (staff panel only)
        if (defined('STAFFINC_DIR')) {
            Signal::connect('ajax.scp', array('PushNotificationsPlugin', 'registerAjaxRoutes'));
            ob_start(array('PushNotificationsPlugin', 'injectAssets'));
        }
    }

    static function bootstrapStatic() {
        if (self::$bootstrapped)
            return;
        self::$bootstrapped = true;

        $pluginDir = dirname(__FILE__) . '/';

        require_once $pluginDir . 'class.PushDispatcher.php';
        Signal::connect('ticket.created', array('PushDispatcher', 'onTicketCreated'));
        Signal::connect('object.created', array('PushDispatcher', 'onObjectCreated'));
        Signal::connect('object.edited', array('PushDispatcher', 'onObjectEdited'));
        Signal::connect('model.updated', array('PushDispatcher', 'onModelUpdated'));
        Signal::connect('cron', array('PushDispatcher', 'onCron'));

        if (defined('STAFFINC_DIR')) {
            Signal::connect('ajax.scp', array('PushNotificationsPlugin', 'registerAjaxRoutes'));
            ob_start(array('PushNotificationsPlugin', 'injectAssets'));
        }
    }

    static function registerAjaxRoutes($dispatcher) {
        $dir = INCLUDE_DIR . 'plugins/push-notifications/';
        $dispatcher->append(
            url('^/push-notifications/', patterns(
                $dir . 'class.PushNotificationsAjax.php:PushNotificationsAjax',
                url_get('^status$', 'getStatus'),
                url_post('^subscribe$', 'subscribe'),
                url_post('^unsubscribe$', 'unsubscribe'),
                url_get('^sw\\.js$', 'serveServiceWorker'),
                url_get('^preferences$', 'getPreferences'),
                url_post('^preferences$', 'savePreferences'),
                url_get('^assets/js$', 'serveJs'),
                url_get('^assets/css$', 'serveCss'),
                url_get('^test$', 'sendTest'),
                url_get('^update/check$', 'checkUpdate'),
                url_post('^update/apply$', 'applyUpdate'),
                url_post('^update/rollback$', 'rollbackUpdate'),
                url_post('^update/channel$', 'setChannel'),
                url_get('^update/backups$', 'listBackups'),
                url_get('^update-manager$', 'serveUpdateManager')
            ))
        );
    }

    static function injectAssets($buffer) {
        // Skip during PJAX requests
        if (!empty($_SERVER['HTTP_X_PJAX']))
            return $buffer;

        // Skip if not an HTML page (AJAX asset responses, JSON, etc.)
        if (strpos($buffer, '</head>') === false
                || strpos($buffer, '</body>') === false)
            return $buffer;

        // Check if plugin is configured with VAPID keys
        try {
            $config = self::getActiveConfig();
        } catch (\Throwable $e) {
            return $buffer;
        }
        if (!$config
            || !$config->get('push_enabled')
            || !$config->get('vapid_public_key')
        ) {
            return $buffer;
        }

        $base = ROOT_PATH . 'scp/ajax.php/push-notifications';
        $dir = dirname(__FILE__) . '/assets/';
        $v = max(
            @filemtime($dir . 'push-notifications.js'),
            @filemtime($dir . 'push-notifications.css')
        ) ?: time();

        $css = sprintf(
            '<link rel="stylesheet" type="text/css" href="%s/assets/css?v=%s">',
            $base, $v);

        // Inline config for the client JS
        global $ost;
        $csrfToken = $ost ? $ost->getCSRFToken() : '';
        $vapidPublicKey = $config->get('vapid_public_key');

        // Translatable UI strings for the client JS
        // Use strings that exist in osTicket's translation catalog where possible.
        // For custom phrases, compose from translated words or use sprintf(__()).
        $strings = json_encode(array(
            'pushNotifications'    => __('Alerts and Notices'),
            'enabled'              => __('Enabled'),
            'disabled'             => __('Disabled'),
            'notifPreferences'     => __('Alerts and Notices'),
            'prefTitle'            => __('Alerts and Notices'),
            'prefClose'            => __('Close'),
            'eventTypes'           => __('Alerts'),
            'eventTypesHint'       => '',
            'newTicket'            => __('New Ticket Alert'),
            'newMessage'           => __('New Message Alert'),
            'ticketAssignment'     => __('Assignment Alert'),
            'ticketTransfer'       => __('Ticket Transfer Alert'),
            'overdueTicket'        => __('Ticket Overdue Alerts'),
            'departments'          => __('Departments'),
            'departmentsHint'      => '',
            'noDepartments'        => __('Departments'),
            'quietHours'           => __('Schedule'),
            'quietHoursHint'       => '',
            'from'                 => __('From'),
            'to'                   => __('To'),
            'clear'                => __('Reset'),
            'cancel'               => __('Cancel'),
            'save'                 => __('Save'),
            'saving'               => __('Save') . '...',
            'loading'              => __('Loading') . '...',
            'loadFailed'           => __('Error'),
            'prefsSaved'           => __('Updated'),
            'prefsSaveFailed'      => __('Error'),
            'pushEnabled'          => __('Enabled'),
            'pushDisabled'         => __('Disabled'),
            'pushBlocked'          => __('Disabled'),
            'pushFailed'           => __('Error'),
            'iosHint'              => __('Settings'),
        ));

        $inlineConfig = '<script type="text/javascript">'
            . 'window.__PUSH_CONFIG={'
            . 'vapidPublicKey:' . json_encode($vapidPublicKey) . ','
            . 'swUrl:' . json_encode($base . '/sw.js') . ','
            . 'subscribeUrl:' . json_encode($base . '/subscribe') . ','
            . 'unsubscribeUrl:' . json_encode($base . '/unsubscribe') . ','
            . 'statusUrl:' . json_encode($base . '/status') . ','
            . 'preferencesUrl:' . json_encode($base . '/preferences') . ','
            . 'csrfToken:' . json_encode($csrfToken) . ','
            . 'strings:' . $strings
            . '};</script>';

        $js = sprintf(
            '<script type="text/javascript" src="%s/assets/js?v=%s"></script>',
            $base, $v);

        $buffer = str_replace('</head>', $css . "\n</head>", $buffer);
        $buffer = str_replace('</body>', $inlineConfig . "\n" . $js . "\n</body>", $buffer);

        // Inject Updates tab on our plugin config page (admin only)
        global $thisstaff;
        if ($thisstaff && $thisstaff->isAdmin()
            && strpos($buffer, 'VAPID Subject') !== false) {
            $tabCode = self::buildConfigTabs($config, $base, $csrfToken);
            $buffer = str_replace('</body>', $tabCode . "\n</body>", $buffer);
        }

        return $buffer;
    }

    /**
     * Build tabbed config UI with Settings + Updates tabs.
     * Injected into the plugin config page only.
     */
    static function buildConfigTabs($config, $base, $csrfToken) {
        $updateBadge = '';
        if ($config) {
            $uj = $config->get('update_available');
            if ($uj) {
                $ud = json_decode($uj, true);
                if (is_array($ud) && !empty($ud['version']))
                    $updateBadge = '<span class="pn-badge">'
                        . htmlspecialchars($ud['version']) . '</span>';
            }
        }

        $tpl = <<<'ENDHTML'
<style>
.pn-tab-bar{display:flex;border-bottom:2px solid #ddd;margin:0 0 15px}
.pn-tab{padding:10px 20px;border:none;background:none;cursor:pointer;font-size:14px;font-weight:600;color:#888;position:relative;bottom:-2px;border-bottom:2px solid transparent;transition:all .15s}
.pn-tab:hover{color:#444}.pn-tab.pna{color:#1a73e8;border-bottom-color:#1a73e8}
.pn-badge{background:#ea4335;color:#fff;font-size:10px;padding:2px 7px;border-radius:10px;margin-left:6px;font-weight:700}
#pn-upd{padding:8px 0}
.pn-card{background:#fff;border:1px solid #e0e0e0;border-radius:8px;margin-bottom:14px;overflow:hidden}
.pn-card-hd{padding:12px 16px;border-bottom:1px solid #e0e0e0;font-weight:600;font-size:14px;display:flex;align-items:center;justify-content:space-between}
.pn-card-bd{padding:14px 16px}
.pn-st{padding:11px 14px;border-radius:6px;margin-bottom:10px;font-size:13px;display:flex;align-items:center;gap:10px}
.pn-st-ok{background:#ecfdf5;border:1px solid #a7f3d0;color:#16a34a}
.pn-st-av{background:#eff6ff;border:1px solid #bfdbfe;color:#1a73e8}
.pn-st-er{background:#fef2f2;border:1px solid #fecaca;color:#dc2626}
.pn-st-wt{background:#f0f4ff;border:1px solid #bfdbfe;color:#6b7280}
.pn-cg{display:grid;grid-template-columns:repeat(4,1fr);gap:8px}
.pn-co{cursor:pointer}.pn-co input{display:none}
.pn-cc{padding:10px;border:2px solid #e0e0e0;border-radius:6px;text-align:center;transition:all .15s}
.pn-co input:checked+.pn-cc{border-color:#1a73e8;background:#eff6ff}
.pn-cc:hover{border-color:#93b4e8}
.pn-cn{font-weight:700;font-size:13px}.pn-cd{font-size:10px;color:#888}
.pn-btn{display:inline-flex;align-items:center;gap:5px;padding:7px 16px;border-radius:5px;font-size:12px;font-weight:600;cursor:pointer;border:none;transition:all .15s}
.pn-btn:disabled{opacity:.5;cursor:not-allowed}
.pn-btn-p{background:#1a73e8;color:#fff}.pn-btn-p:hover:not(:disabled){background:#155ab0}
.pn-btn-o{background:transparent;color:#333;border:1px solid #ddd}.pn-btn-o:hover:not(:disabled){background:#f6f6f6}
.pn-btn-d{background:#dc2626;color:#fff}.pn-btn-d:hover:not(:disabled){background:#b91c1c}
.pn-btn-w{background:#d97706;color:#fff}.pn-btn-w:hover:not(:disabled){background:#b45309}
.pn-btn-s{padding:4px 10px;font-size:11px}
.pn-dt table{width:100%;border-collapse:collapse;font-size:13px}
.pn-dt td{padding:6px 10px;border-bottom:1px solid #e0e0e0}
.pn-dt td:first-child{font-weight:600;width:120px;color:#888}
.pn-uc{border:1px solid #e0e0e0;border-radius:6px;padding:12px 14px;margin-bottom:10px}
.pn-uc-mi{border-left:3px solid #16a34a}
.pn-uc-ma{border-left:3px solid #d97706}
.pn-uc-tl{font-weight:700;font-size:13px;margin-bottom:8px;display:flex;align-items:center;gap:6px}
.pn-uc-mi .pn-uc-tl{color:#16a34a}.pn-uc-ma .pn-uc-tl{color:#d97706}
.pn-uc-wr{font-size:11px;color:#d97706;margin:6px 0 2px;padding:6px 10px;background:#fffbeb;border:1px solid #fde68a;border-radius:4px}
.pn-pr{display:none;margin-top:10px}.pn-prb{height:5px;background:#ddd;border-radius:3px;overflow:hidden}
.pn-prf{height:100%;background:#1a73e8;border-radius:3px;transition:width .4s;width:0}
.pn-prt{font-size:11px;color:#888;margin-top:4px}
.pn-log{margin-top:8px;padding:10px;background:#111827;color:#d1d5db;border-radius:5px;font-family:monospace;font-size:11px;max-height:160px;overflow-y:auto;display:none;line-height:1.7}
.pn-log .lok{color:#34d399}.pn-log .ler{color:#f87171}.pn-log .lin{color:#60a5fa}
.pn-bkl{list-style:none;margin:0;padding:0}
.pn-bkl li{display:flex;align-items:center;justify-content:space-between;padding:8px 0;border-bottom:1px solid #e0e0e0;font-size:12px}
.pn-bkl li:last-child{border-bottom:none}
.pn-bkn{font-weight:600}.pn-bkm{color:#888;font-size:11px}
.pn-emp{color:#888;font-size:13px;text-align:center;padding:20px 0}
.pn-act{display:flex;gap:8px;margin-top:10px;flex-wrap:wrap}
.pn-sp{display:inline-block;width:14px;height:14px;border:2px solid transparent;border-top-color:currentColor;border-radius:50%;animation:pns .6s linear infinite}
@keyframes pns{to{transform:rotate(360deg)}}
.pn-chb{display:inline-block;padding:1px 6px;border-radius:8px;font-size:10px;font-weight:600}
.pn-chb-s{background:#ecfdf5;color:#16a34a;border:1px solid #a7f3d0}
.pn-chb-r{background:#eff6ff;color:#1a73e8;border:1px solid #bfdbfe}
.pn-chb-b{background:#fffbeb;color:#d97706;border:1px solid #fde68a}
.pn-chb-d{background:#fef2f2;color:#dc2626;border:1px solid #fecaca}
@media(prefers-color-scheme:dark){
.pn-card{background:#1e293b;border-color:#334155}.pn-card-hd{border-color:#334155;color:#e2e8f0}
.pn-tab{color:#94a3b8}.pn-tab.pna{color:#3b82f6;border-bottom-color:#3b82f6}
.pn-tab-bar{border-color:#475569}
.pn-cc{border-color:#475569;color:#e2e8f0}.pn-co input:checked+.pn-cc{border-color:#3b82f6;background:#172554}
.pn-btn-o{color:#e2e8f0;border-color:#475569}.pn-btn-o:hover:not(:disabled){background:#334155}
.pn-dt td{border-color:#334155;color:#e2e8f0}.pn-dt td:first-child{color:#94a3b8}
.pn-bkl li{border-color:#334155}.pn-emp,.pn-bkm,.pn-cd,.pn-prt{color:#94a3b8}
.pn-cn,.pn-bkn,.pn-card-bd{color:#e2e8f0}#pn-upd{color:#e2e8f0}.pn-prb{background:#475569}
.pn-uc{border-color:#475569}.pn-uc-mi{border-left-color:#22c55e}.pn-uc-ma{border-left-color:#eab308}
.pn-uc-mi .pn-uc-tl{color:#22c55e}.pn-uc-ma .pn-uc-tl{color:#eab308}
.pn-uc-wr{background:#422006;border-color:#854d0e;color:#eab308}
}
@media(max-width:600px){.pn-cg{grid-template-columns:repeat(2,1fr)}}
</style>
<script>
(function(){
var B="__PH_BASE__",C="__PH_CSRF__",BG="__PH_BADGE__";
var f=document.querySelector('form[action*="plugins.php?id="]');
if(!f)return;
var p=f.parentNode,tb=document.createElement("div");
tb.className="pn-tab-bar";
tb.innerHTML='<button class="pn-tab pna" data-t="s" type="button">Settings</button><button class="pn-tab" data-t="u" type="button">Updates'+BG+'</button>';
p.insertBefore(tb,f);
var up=document.createElement("div");up.id="pn-upd";up.style.display="none";
up.innerHTML='<div class="pn-card"><div class="pn-card-hd">Release Channel</div><div class="pn-card-bd"><div class="pn-cg" id="pnCh">'+
'<label class="pn-co"><input type="radio" name="pnc" value="stable"><div class="pn-cc"><div class="pn-cn">Stable</div><div class="pn-cd">Production releases</div></div></label>'+
'<label class="pn-co"><input type="radio" name="pnc" value="rc"><div class="pn-cc"><div class="pn-cn">RC</div><div class="pn-cd">Release candidates</div></div></label>'+
'<label class="pn-co"><input type="radio" name="pnc" value="beta"><div class="pn-cc"><div class="pn-cn">Beta</div><div class="pn-cd">May have bugs</div></div></label>'+
'<label class="pn-co"><input type="radio" name="pnc" value="dev"><div class="pn-cc"><div class="pn-cn">Dev</div><div class="pn-cd">Latest builds</div></div></label>'+
'</div></div></div>'+
'<div class="pn-card"><div class="pn-card-hd"><span>Available Updates</span><button class="pn-btn pn-btn-o pn-btn-s" type="button" onclick="pnCk()">Check Now</button></div>'+
'<div class="pn-card-bd"><div id="pnS" class="pn-st pn-st-wt"><span class="pn-sp"></span><span>Checking for updates...</span></div>'+
'<div id="pnD" class="pn-dt" style="display:none"></div>'+
'<div id="pnPr" class="pn-pr"><div class="pn-prb"><div id="pnPf" class="pn-prf"></div></div><div id="pnPt" class="pn-prt"></div></div>'+
'<div id="pnLg" class="pn-log"></div></div></div>'+
'<div class="pn-card"><div class="pn-card-hd"><span>Backups</span><button class="pn-btn pn-btn-o pn-btn-s" type="button" onclick="pnBk()">Refresh</button></div>'+
'<div class="pn-card-bd"><div id="pnBl"><div class="pn-emp">Loading...</div></div></div></div>';
p.insertBefore(up,f.nextSibling);
var ld=false;
tb.querySelectorAll(".pn-tab").forEach(function(t){t.addEventListener("click",function(){
tb.querySelectorAll(".pn-tab").forEach(function(x){x.classList.remove("pna")});
t.classList.add("pna");var w=t.getAttribute("data-t");
f.style.display=w==="s"?"":"none";up.style.display=w==="u"?"":"none";
if(w==="u"&&!ld){ld=true;pnCk();pnBk();}
})});
if(location.hash==="#updates")tb.querySelectorAll(".pn-tab")[1].click();
var lc=null,cc="stable";
function ax(m,u,b,cb){var x=new XMLHttpRequest();x.open(m,u,true);x.setRequestHeader("X-CSRFToken",C);if(b)x.setRequestHeader("Content-Type","application/json");x.onload=function(){try{cb(JSON.parse(x.responseText),null)}catch(e){cb(null,x.responseText||"Error")}};x.onerror=function(){cb(null,"Network error")};x.send(b?JSON.stringify(b):null)}
function el(i){return document.getElementById(i)}
function esc(s){return s?String(s).replace(/&/g,"&amp;").replace(/</g,"&lt;").replace(/>/g,"&gt;").replace(/"/g,"&quot;"):""}
function ss(c,h){var e=el("pnS");e.className="pn-st "+c;e.innerHTML=h}
function lg(m,t){var l=el("pnLg");l.style.display="block";var d=document.createElement("div");d.className=t?"l"+t:"";d.textContent=m;l.appendChild(d);l.scrollTop=l.scrollHeight}
function pg(pct,t){el("pnPr").style.display="block";el("pnPf").style.width=pct+"%";el("pnPt").textContent=t}
function chb(ch){var m={stable:["Stable","s"],rc:["RC","r"],beta:["Beta","b"],dev:["Dev","d"]};var v=m[ch]||[ch,"s"];return'<span class="pn-chb pn-chb-'+v[1]+'">'+v[0]+'</span>'}
document.querySelectorAll("#pnCh input[name=pnc]").forEach(function(r){r.addEventListener("change",function(){
if(this.value===cc)return;var nv=this.value;
ax("POST",B+"/update/channel",{channel:nv},function(res,err){if(err||!res||!res.success){sc(cc);return}cc=nv;pnCk()})})});
function sc(ch){cc=ch;document.querySelectorAll("#pnCh input[name=pnc]").forEach(function(r){r.checked=r.value===ch})}
function updCard(type,info,cur){
var isMa=type==="major",cls=isMa?"pn-uc pn-uc-ma":"pn-uc pn-uc-mi";
var title=isMa?"Major Update":"Minor Update";
var b=chb(info.channel||cc);
var h='<div class="'+cls+'"><div class="pn-uc-tl">'+title+'</div>';
h+='<table><tr><td>Version</td><td><strong>v'+esc(info.version)+'</strong> '+b+'</td></tr>';
h+='<tr><td>Installed</td><td>v'+esc(cur)+'</td></tr>';
if(info.published_at)h+='<tr><td>Published</td><td>'+new Date(info.published_at).toLocaleDateString()+'</td></tr>';
h+='</table>';
if(isMa)h+='<div class="pn-uc-wr">&#9888; Major upgrades may include breaking changes. Review release notes before upgrading.</div>';
h+='<div class="pn-act">';
h+='<button class="pn-btn '+(isMa?'pn-btn-w':'pn-btn-p')+'" type="button" onclick="pnAp(\''+esc(info.version)+'\')">'+(isMa?'Upgrade':'Update')+' to v'+esc(info.version)+'</button>';
if(info.html_url)h+='<a href="'+esc(info.html_url)+'" target="_blank" class="pn-btn pn-btn-o">Release Notes</a>';
h+='</div></div>';return h}
window.pnCk=function(){
ss("pn-st-wt",'<span class="pn-sp"></span><span>Checking for updates...</span>');
el("pnD").style.display="none";
el("pnLg").style.display="none";el("pnLg").innerHTML="";el("pnPr").style.display="none";
ax("GET",B+"/update/check",null,function(r,err){
if(err){ss("pn-st-er","&#10060; "+esc(err));return}
lc=r;if(r.current_channel)sc(r.current_channel);
var b=chb(r.channel||cc);
if(r.error){ss("pn-st-er","&#10060; "+esc(r.error)+" "+b);return}
var hasMinor=r.minor&&r.minor.version;
var hasMajor=r.major&&r.major.version;
if(!hasMinor&&!hasMajor){ss("pn-st-ok","&#9989; Up to date "+b+" (v"+esc(r.current_version)+")");return}
var parts=[];
if(hasMinor)parts.push("Minor v"+esc(r.minor.version));
if(hasMajor)parts.push("Major v"+esc(r.major.version));
ss("pn-st-av","&#128640; "+parts.join(" &middot; ")+" available "+b);
var d="";
if(hasMinor)d+=updCard("minor",r.minor,r.current_version);
if(hasMajor)d+=updCard("major",r.major,r.current_version);
el("pnD").innerHTML=d;el("pnD").style.display="block";
})};
window.pnAp=function(version){
if(!version)return;
var isMa=lc&&lc.major&&lc.major.version===version;
var label=isMa?"major upgrade":"minor update";
if(!confirm((isMa?"MAJOR UPGRADE":"Update")+" to v"+version+"?\n\n1. Backup current files + DB\n2. Download v"+version+" from GitHub\n3. Replace plugin files\n4. Run database migrations"+(isMa?"\n\nMajor upgrades may include breaking changes.":"")))return;
el("pnLg").style.display="block";el("pnLg").innerHTML="";
lg("Starting "+label+" to v"+version+"...","in");pg(10,"Creating backup...");
setTimeout(function(){pg(30,"Downloading v"+version+"...")},500);
setTimeout(function(){pg(60,"Installing...")},1200);
ax("POST",B+"/update/apply",{version:version},function(r,err){
if(err){pg(100,"Failed");lg("ERROR: "+err,"er");return}
if(r.success){pg(100,"Done!");lg("Successfully updated to v"+r.new_version,"ok");
ss("pn-st-ok","&#9989; Updated to <strong>v"+esc(r.new_version)+"</strong>");
el("pnD").innerHTML='<div class="pn-act"><button class="pn-btn pn-btn-o" type="button" onclick="location.reload()">Refresh Page</button></div>';
el("pnD").style.display="block";pnBk();
}else{pg(100,"Failed");lg("ERROR: "+(r.error||"Unknown"),"er")}
})};
window.pnBk=function(){
el("pnBl").innerHTML='<div class="pn-emp">Loading...</div>';
ax("GET",B+"/update/backups",null,function(r,err){
if(err||!r){el("pnBl").innerHTML='<div class="pn-emp">Failed to load</div>';return}
var b=r.backups||[];
if(!b.length){el("pnBl").innerHTML='<div class="pn-emp">No backups yet</div>';return}
var h='<ul class="pn-bkl">';
b.forEach(function(k){h+='<li><div><span class="pn-bkn">v'+esc(k.version)+'</span><br><span class="pn-bkm">'+esc(k.date)+'</span></div><button class="pn-btn pn-btn-d pn-btn-s" type="button" data-p="'+esc(k.path)+'" onclick="pnRb(this.dataset.p)">Restore</button></li>'});
h+='</ul>';el("pnBl").innerHTML=h;
})};
window.pnRb=function(path){
if(!confirm("Restore from this backup?\nCurrent files and DB tables will be replaced."))return;
ss("pn-st-wt",'<span class="pn-sp"></span><span>Rolling back...</span>');
ax("POST",B+"/update/rollback",{backup_path:path},function(r,err){
if(err){ss("pn-st-er","&#10060; "+esc(err));return}
if(r.success){ss("pn-st-ok","&#9989; Restored to <strong>v"+(r.restored_version||"?")+"</strong>");pnBk()}
else{ss("pn-st-er","&#10060; "+(r.error||"Failed"))}
})};
})();
</script>
ENDHTML;

        return str_replace(
            array('"__PH_BASE__"', '"__PH_CSRF__"', '"__PH_BADGE__"'),
            array(json_encode($base), json_encode($csrfToken), json_encode($updateBadge)),
            $tpl
        );
    }

    /**
     * Find the active plugin config across all instances.
     * Since this is a single-instance plugin, we look for the first active instance.
     */
    static function getActiveConfig() {
        static $config = null;
        if ($config !== null)
            return $config ?: null;

        // Find active plugin instances
        $sql = "SELECT pi.id FROM " . PLUGIN_INSTANCE_TABLE . " pi"
            . " JOIN " . PLUGIN_TABLE . " p ON (pi.plugin_id = p.id)"
            . " WHERE p.isphar = 0 AND p.isactive = 1 AND (pi.flags & 1) = 1"
            . " AND p.install_path = 'plugins/push-notifications'";
        $res = db_query($sql);
        if ($res && ($row = db_fetch_row($res))) {
            $instance = PluginInstance::lookup($row[0]);
            if ($instance) {
                $config = $instance->getConfig();
                return $config;
            }
        }

        $config = false;
        return null;
    }
}

// Static bootstrap: ensures Signal hooks and AJAX routes load even with 0 instances.
// Plugin class file is loaded during discovery, so this runs on every request.
PushNotificationsPlugin::bootstrapStatic();
