var ws;
$(document).ready(function() {
    //$('body').on('touchstart.dropdown', '.dropdown-menu', function (e) { e.stopPropagation(); });

    if ($("[rel=tooltip]").length) {
        $("[rel=tooltip]").tooltip({
            placement: "bottom",
            animation: false
        });
    }

    // add the autocomplete search thing
    $('#searchbox').zz_search( function(data, event) { window.location = '/' + data.type + '/' + data.id + '/'; event.preventDefault(); } );

    // prevent firing of window.location in table rows if a link is clicked directly
    $('.killListRow a').click(function(e) {
        e.stopPropagation();
    });

    // See if we are embedded in an iframe on another website perhaps?
    if (top !== self) {
        $("#iframed").modal('show');
    }

    // Send that CREST URL to the parser, allows the website to parse the killmail so that
    // it is ready for the user when they click submit
    $("#killmailurl").bind('paste', function(event) {
        setTimeout(sendCrestUrl, 1);
    });

    // setup websocket with callbacks
    if (start_websocket) {
    ws = new ReconnectingWebSocket('wss://' + window.location.hostname + '/websocket/', '', {maxReconnectAttempts: 15});
    ws.onmessage = function(event) {
        wslog(event.data);
    };
    ws.onopen = function(event) {
        pubsub('public');
        // If we connected and somehow got completely disconnected - reload the page
        ws.onclose = function(event) {
            //window.location = window.location;
        }
      }
    }

    addKillListClicks();

    /*var pathname = $(location).attr('pathname');
    console.log(pathname.substr(0,9));
    if (pathname != '/map/' && pathname.substr(0, 9) != '/account/') {
        $("a[href='/']").on('click', function(event) { doLoad($(this).attr('href')); return false; } );
        addPartials();
        console.log($(location).attr('pathname'));
    }*/
});

function htmlNotify (data) 
{
    if("Notification" in window) {
        if (Notification.permission !== 'denied' && Notification.permission !== "granted") {
            Notification.requestPermission(function (permission) {
                if (permission === 'granted') htmlNotify(data);
            });
            return;
        }
        if (Notification.permission === 'granted') {
            var notif = new Notification(data.title, {
                body: data.iskStr,
                icon: data.image,
                tag: data.url
            });
            setTimeout(function() { notif.close() }, 20000);
            notif.onclick = function () {
                notif.close();
                window.open(data.url).focus();
            };
        }
    }
}

function wslog(msg)
{
    if (msg === 'ping' || msg === 'pong') return;
    json = JSON.parse(msg);
    if (json.action === 'tqStatus') {
        tqStatus = json.tqStatus;
        tqCount = json.tqCount;
        if (tqStatus === 'OFFLINE' || tqStatus === 'UNKNOWN') {
            html = '<span class="red">TQ ' + tqCount + "</span>";
        } else {
            html = '<span class="green">TQ ' + tqCount + "</span>";
        }
        $("#tqStatus").html(html);
        $("#lasthour").text(json.kills);
    } else if (json.action === 'reload') {
        console.log('Reload imminent in the next 5 minutes');
        setTimeout("location.reload();", Math.floor(1 + (Math.random() * 500000)));
    } else if (json.action === 'bigkill') {
        htmlNotify(json);
    } else if (json.action === 'lastHour') {
        $("#lasthour").text(json.kills);
    } else if (json.action === 'audio') {
        audio(json.uri);
    } else if (json.action === 'comment') {
        $("#commentblock").html(json.html);
    } else if (json.action === 'littlekill') {
        var killID = json.killID;
        setTimeout(function() { loadLittleMail(killID); }, 1);
    } else {
        console.log("Unknown action: " + json.action);
    }
}

function loadLittleMail(killID) {
        // Add the killmail to the kill list
        $.get("/cache/1hour/killlistrow/" + killID + "/", function(data) {
            if (!(showAds != 0 && typeof fusetag == 'undefined')) {
                var data = $(data);
                data.on('click', function(event) {
                    if (event.which === 2) return false;
                    window.location = '/kill/' + $(this).attr('killID') + '/';
                    return false;
                });
                if (showAds == 0) $("#killlist tbody tr").first().before(data);
                else $("#killlist tbody tr").eq(0).after(data);
            }
            // Keep the page from growing too much...
            while ($("#killlist tbody tr").length > 50) $("#killlist tbody tr:last").remove();
            // Tell the user what's going on and not to expect sequential killmails
            if ($("#livefeednotif").length == 0) {
                if (showAds != 0 && typeof fusetag == 'undefined')
                    $("#killlist thead tr").after("<tr><td id='livefeednotif' colspan='7'><strong><em>Live feed disabled when adblockers present.</em></strong></td></tr>");
                else
                    $("#killlist thead tr").after("<tr><td id='livefeednotif' colspan='7'><strong><em>Live feed - killmails may be out of order.</em></strong></td></tr>");
            }
        });
}

function audio(uri)
{
    var audio = new Audio(uri);
    audio.volume = 0.1;
    audio.play();
}

function saveFitting(id) {
    $('#modalMessageBody').html('Saving fit....');
    $('#modalMessage').modal({backdrop: true, keyboard: true, show: true});

    var request = $.ajax({
        url: "/ccpsavefit/" + id + "/",
        type: "GET",
        dataType: "text"
    });

    request.done(function(msg) {
        $('#modalMessageBody').html(msg);
        $('#modalMessage').modal({backdrop: true, keyboard: true, show: true});
    });
}


function hideSortStuff(doHide)
{
    if (doHide) $(".hide-when-sorted").hide();
    else $(".hide-when-sorted").show();
}

function sendCrestUrl() {
    str = $("#killmailurl").val();
    strSplit = str.split("/");
    if (strSplit.length === 8) strSplit.shift();
    killID = strSplit[4];
    hash = strSplit[5];
    a = ['/crestmail/', killID, '/', hash, '/'];
    url = a.join('');
    $.get(url);
}

function addToolTip(el, msg) {
    $('#tipmsg').html(msg);
    var tt = $('#ttooltip').first();

    var pos = el.offset();

    tt.css({
        position: 'absolute',
        top: pos.top + 45,
        left: pos.left - 200
    }).addClass('active').removeClass('hidden');
}

function loadPartial(url) {
    setTimeout("doLoad('" + url + "');", 1);
    return false;
}

function addPartials() {
    //var partials = ['kill', 'faction', 'system', 'region', 'group', 'ship', 'location'];
    var partials = ['kill', 'character', 'corporation', 'alliance', 'faction', 'system', 'region', 'group', 'ship', 'location'];
    for (partial of partials) {
        console.log("Adding partial for " + partial);
        $(".pagecontent a[href^='/" + partial + "/']").on('click', function(event) { doLoad($(this).attr('href')); return false; } );
    }
}

function loadCompleted() {
    window.scrollTo(0, 0);
    NProgress.done();
    //addPartials();
    addKillListClicks();
    $('#tracker-dropdown').load('/navbar/');
}

function doLoad(url) {
    return;
    //console.log("Loading: " + url);
    var pathname = window.location.pathname;
    var state = { 'href' : pathname };
    NProgress.start();
    $(".pagecontent").load('/partial' + url, null, loadCompleted);
    history.pushState(state, null, url);
}

// Revert to a previously saved state
window.addEventListener('popstate', function(event) {
    //window.location = window.location;
});

function addKillListClicks()
{
    $(".killListRow").on('click', function(event) {
        if (event.which === 2) return false;
        console.log($(this).attr('killID'));
        window.location = '/kill/' + $(this).attr('killID') + '/';
        return false;
    });
}

function doSponsor(url)
{
    $('#modalMessageBody').load(url);
    $('#modalTitle').text('Sponsor this killmail');
    $('#modalMessage').modal({backdrop: true, keyboard: true, show: true});
}

function doFavorite(killID) {
    var color = $("#fav-star-killmail").css("color");
    var action = (color === "rgb(128, 128, 128)") ? "save" : "remove";
    var url = '/account/favorite/' + killID + '/' + action + '/';
    $.post(url, function( data ) {
        result = JSON.parse(data); console.log(result);
        $("#fav-star-killmail").css("color", result.color);
        $('#modalTitle').text('Favorites');
        $('#modalMessageBody').text(result.message);
        $('#modalMessage').modal({backdrop: true, keyboard: true, show: true});
    });
}

function pubsub(channel)
{
    try {
        ws.send(JSON.stringify({'action':'sub', 'channel': channel}));
        console.log("subscribing to " + channel);
    } catch (e) {
        setTimeout("pubsub('" + channel + "');", 1000);
    }
}

function curday()
{
    today = new Date();
    var dd = today.getDate();
    var mm = today.getMonth()+1; //As January is 0.
    var yyyy = today.getFullYear();

    if(dd<10) dd='0'+dd;
    if(mm<10) mm='0'+mm;
    return (yyyy+mm+dd);
};

function commentUpVote(pageID, commentID) 
{
    if (showAds == 0 || typeof fusetag != "undefined") $.ajax("/cache/bypass/comment/" + pageID + "/" + commentID + "/up/");
    else annoyAdBlockers();
}

var adnumber = 0;
function loadads() {
    var adblocks = $(".publift:visible");
    adnumber = adblocks.length;
    adblocks.each(function() {
            var elem = $(this);
            var fuse = elem.attr("fuse");
            elem.load('/cache/1hour/publift/' + fuse + '/', adblockloaded);
    });
}

var bottomad = null;
function adblockloaded() {
    adnumber--;
    if (adnumber <= 0) {
        try {
            bottomad = $("#adsensebottom").detach();
            $("#detailadrow").html(bottomad.html());
            killListAd(false);
            fusetag.loadSlots();
            setTimeout(adBlockCheck, 5000);
        } catch (e) {
            adBlockCheck();
        }
    }
}

function killListAd(doLoadSlots) {
    if ($(".adrow").length == 0 && bottomad != null) {
        var td = $("<td colspan='8' style='width: 100%;'>") ; bottomad.appendTo(td); var tr = $("<tr class='killlistrow adrow ad-xl-none'>").append(td).insertBefore("#killlist tbody tr:first");
        if (doLoadSlots === true) fusetag.loadSlots();
    }
}

function adBlockCheck() {
    if (showAds != 0 && typeof fusetag == "undefined") {
        console.log("Ads are blocked :(");
        $("#adsensetop, #adsensebottom").html('<a target="_new" href="https://zkillboard.com/cache/bypass/login/patreon/"><img src="/img/patreon_lg.jpg"></a>');
        var today = curday();
        if (!localStorage.getItem('adblocker-nag-' + today)) {
            localStorage.setItem('adblocker-nag-' + today, true);
            annoyAdBlockers();
        }
    }
}

function annoyAdBlockers() {
    if (showAds != 0 && typeof fusetag == "undefined") {
            $(this).blur();
            $('#modalMessageBody').html('<h2>Would you kindly unblock ads?</h2><p>zKillboard only shows 2 advertisements and the ads are designed to be non-intrusive of your viewing experience. Please support zKillboard by disabling your adblocker.</p><p><a href="/information/payments/">Or block them with ISK and get a golden wreck too.</a></p><p><a target="_new" href="https://www.patreon.com/zkillboard"><img src="/img/patreon_lg.jpg"></a></p><p><a target="_new" href="https://brave.com/zki349"><img src="//zkillboard.com/img/brave_switch.png" alt="Switch to the Brave Browser"></a></p>');
            $('#modalMessage').modal({backdrop: true, keyboard: true, show: true});

    }
}

var now = time();
var today = now - (now % 86400);
var week = now - (now % 604800);
function knowledgeCheck() {
    if (typeof window.obsstudio != 'undefined') return;
    if (!localStorage.getItem('knowledgecheck-' + week)) {
        likeOMGwhereAREtheKILLMAILS();
    }

}

function likeOMGwhereAREtheKILLMAILS() {
    $(document).blur();
    $('#modalMessageBody').html('<h4>zKillboard does NOT automatically get all killmails</h4><p>zKillboard does not get all killmails automatically. CCP does not make killmails public. They must be provided by various means.</p><ul><li>Someone manually posts the killmail.</li><li>A character has authorized zKillboard to retrieve their killmails.</li><li>A corporation director or CEO has authorized zKillboard to retrieve their corporation\'s killmails.</li><li>War killmail (victim and final blow have a Concord sanctioned war with each other)</li></ul><p>The killmail API works just like killmails do in game. The victim gets the killmail, and the person with the finalblow gets the killmail. Therefore, for zKillboard to be able to retrieve the killmail via API it must have the character or corporation API submitted for the victim or the person with the final blow. If an NPC gets the final blow, the last character to aggress to the victim will receive the killmail and credit for the final blow.</p><p>Remember, every PVP killmail has two sides, the victim and the aggressors. Victims often don\'t want their killmails to be made public, however, the aggressors do.</p><btn onclick="okIgetit();" class="btn btn-success btn-block">OK</btn>');
    $('#modalCloseButton').hide();
    $('#modalMessage').modal({backdrop: 'static', keyboard: false, show: true});
}

function okIgetit() {
    console.log('*sigh*');
    $('#modalCloseButton').show();
    $('#modalMessage').modal('hide');
    try {
        localStorage.setItem('knowledgecheck-' + week, true);
    } catch (e) {
        console.log(e);
        alert('Something prevented the site from saving that you acknowledged the popup...');
    }
}

function time() {
    return Math.floor(Date.now() / 1000);
}

// gtcplex320.jpg  gtcplex728.jpg  merch320.jpg  merch728.jpg
var banner_links = ['https://store.markeedragon.com/affiliate.php?id=928&redirect=index.php?cat=4', 'https://www.zazzle.com/store/zkillboard/products'];
var banners_sm = ['/img/banners/gtcplex320.jpg', '/img/banners/merch320.jpg'];
var banners_lg = ['/img/banners/gtcplex728.jpg?1', '/img/banners/merch728.jpg'];
function otherBanners() {
    if (showAds != 1) return;
    if ($("#adsensetop:visible").length > 0) return;


    var minute = new Date().getMinutes();
    var mod = minute % 2; // number of other banners
    $('#otherBannerAnchor').attr('href', banner_links[mod]);
    $('#otherBannerImg').attr('src', banners_lg[mod]);
    $("#otherBannerDiv").css('display', 'block');
    setTimeout(otherBanners, Math.min(30000, 1000 * (61 - new Date().getSeconds())));
}
