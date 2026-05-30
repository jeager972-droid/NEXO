import{j as t,e as mo,f as Ho,r as f,B as $o,V as on,u as Nn,c as Uo,M as Go,C as Vo,E as Ko,a as Jo,b as ho,S as go,R as xo,d as Zo}from"./three-vendor-CXH9RDG6.js";import{g as _}from"./gsap-vendor-SFc2wnMY.js";(function(){const e=document.createElement("link").relList;if(e&&e.supports&&e.supports("modulepreload"))return;for(const n of document.querySelectorAll('link[rel="modulepreload"]'))r(n);new MutationObserver(n=>{for(const i of n)if(i.type==="childList")for(const s of i.addedNodes)s.tagName==="LINK"&&s.rel==="modulepreload"&&r(s)}).observe(document,{childList:!0,subtree:!0});function o(n){const i={};return n.integrity&&(i.integrity=n.integrity),n.referrerPolicy&&(i.referrerPolicy=n.referrerPolicy),n.crossOrigin==="use-credentials"?i.credentials="include":n.crossOrigin==="anonymous"?i.credentials="omit":i.credentials="same-origin",i}function r(n){if(n.ep)return;n.ep=!0;const i=o(n);fetch(n.href,i)}})();function Qo(a,e){for(var o=0;o<e.length;o++){var r=e[o];r.enumerable=r.enumerable||!1,r.configurable=!0,"value"in r&&(r.writable=!0),Object.defineProperty(a,r.key,r)}}function ei(a,e,o){return e&&Qo(a.prototype,e),a}/*!
 * Observer 3.15.0
 * https://gsap.com
 *
 * @license Copyright 2008-2026, GreenSock. All rights reserved.
 * Subject to the terms at https://gsap.com/standard-license
 * @author: Jack Doyle, jack@greensock.com
*/var we,Zr,Je,Pt,At,lr,yo,Yt,cr,bo,jt,lt,vo,wo=function(){return we||typeof window<"u"&&(we=window.gsap)&&we.registerPlugin&&we},ko=1,sr=[],D=[],mt=[],Sr=Date.now,wn=function(e,o){return o},ti=function(){var e=cr.core,o=e.bridge||{},r=e._scrollers,n=e._proxies;r.push.apply(r,D),n.push.apply(n,mt),D=r,mt=n,wn=function(s,l){return o[s](l)}},Ot=function(e,o){return~mt.indexOf(e)&&mt[mt.indexOf(e)+1][o]},Er=function(e){return!!~bo.indexOf(e)},We=function(e,o,r,n,i){return e.addEventListener(o,r,{passive:n!==!1,capture:!!i})},Oe=function(e,o,r,n){return e.removeEventListener(o,r,!!n)},Ir="scrollLeft",Xr="scrollTop",kn=function(){return jt&&jt.isPressed||D.cache++},an=function(e,o){var r=function n(i){if(i||i===0){ko&&(Je.history.scrollRestoration="manual");var s=jt&&jt.isPressed;i=n.v=Math.round(i)||(jt&&jt.iOS?1:0),e(i),n.cacheID=D.cache,s&&wn("ss",i)}else(o||D.cache!==n.cacheID||wn("ref"))&&(n.cacheID=D.cache,n.v=e());return n.v+n.offset};return r.offset=0,e&&r},Xe={s:Ir,p:"left",p2:"Left",os:"right",os2:"Right",d:"width",d2:"Width",a:"x",sc:an(function(a){return arguments.length?Je.scrollTo(a,pe.sc()):Je.pageXOffset||Pt[Ir]||At[Ir]||lr[Ir]||0})},pe={s:Xr,p:"top",p2:"Top",os:"bottom",os2:"Bottom",d:"height",d2:"Height",a:"y",op:Xe,sc:an(function(a){return arguments.length?Je.scrollTo(Xe.sc(),a):Je.pageYOffset||Pt[Xr]||At[Xr]||lr[Xr]||0})},Ye=function(e,o){return(o&&o._ctx&&o._ctx.selector||we.utils.toArray)(e)[0]||(typeof e=="string"&&we.config().nullTargetWarn!==!1?console.warn("Element not found:",e):null)},ri=function(e,o){for(var r=o.length;r--;)if(o[r]===e||o[r].contains(e))return!0;return!1},Wt=function(e,o){var r=o.s,n=o.sc;Er(e)&&(e=Pt.scrollingElement||At);var i=D.indexOf(e),s=n===pe.sc?1:2;!~i&&(i=D.push(e)-1),D[i+s]||We(e,"scroll",kn);var l=D[i+s],d=l||(D[i+s]=an(Ot(e,r),!0)||(Er(e)?n:an(function(h){return arguments.length?e[r]=h:e[r]})));return d.target=e,l||(d.smooth=we.getProperty(e,"scrollBehavior")==="smooth"),d},jn=function(e,o,r){var n=e,i=e,s=Sr(),l=s,d=o||50,h=Math.max(500,d*3),m=function(y,k){var M=Sr();k||M-s>d?(i=n,n=y,l=s,s=M):r?n+=y:n=i+(y-i)/(M-l)*(s-l)},x=function(){i=n=r?0:n,l=s=0},g=function(y){var k=l,M=i,v=Sr();return(y||y===0)&&y!==n&&m(y),s===l||v-l>h?0:(n+(r?M:-M))/((r?v:s)-k)*1e3};return{update:m,reset:x,getVelocity:g}},yr=function(e,o){return o&&!e._gsapAllow&&e.cancelable!==!1&&e.preventDefault(),e.changedTouches?e.changedTouches[0]:e},qn=function(e){var o=Math.max.apply(Math,e),r=Math.min.apply(Math,e);return Math.abs(o)>=Math.abs(r)?o:r},jo=function(){cr=we.core.globals().ScrollTrigger,cr&&cr.core&&ti()},_o=function(e){return we=e||wo(),!Zr&&we&&typeof document<"u"&&document.body&&(Je=window,Pt=document,At=Pt.documentElement,lr=Pt.body,bo=[Je,Pt,At,lr],we.utils.clamp,vo=we.core.context||function(){},Yt="onpointerenter"in lr?"pointer":"mouse",yo=re.isTouch=Je.matchMedia&&Je.matchMedia("(hover: none), (pointer: coarse)").matches?1:"ontouchstart"in Je||navigator.maxTouchPoints>0||navigator.msMaxTouchPoints>0?2:0,lt=re.eventTypes=("ontouchstart"in At?"touchstart,touchmove,touchcancel,touchend":"onpointerdown"in At?"pointerdown,pointermove,pointercancel,pointerup":"mousedown,mousemove,mouseup,mouseup").split(","),setTimeout(function(){return ko=0},500),Zr=1),cr||jo(),Zr};Xe.op=pe;D.cache=0;var re=function(){function a(o){this.init(o)}var e=a.prototype;return e.init=function(r){Zr||_o(we)||console.warn("Please gsap.registerPlugin(Observer)"),cr||jo();var n=r.tolerance,i=r.dragMinimum,s=r.type,l=r.target,d=r.lineHeight,h=r.debounce,m=r.preventDefault,x=r.onStop,g=r.onStopDelay,c=r.ignore,y=r.wheelSpeed,k=r.event,M=r.onDragStart,v=r.onDragEnd,L=r.onDrag,X=r.onPress,E=r.onRelease,ke=r.onRight,G=r.onLeft,S=r.onUp,ye=r.onDown,Fe=r.onChangeX,j=r.onChangeY,fe=r.onChange,T=r.onToggleX,ht=r.onToggleY,le=r.onHover,Le=r.onHoverEnd,Ne=r.onMove,$=r.ignoreCheck,ne=r.isNormalizer,oe=r.onGestureStart,u=r.onGestureEnd,ce=r.onWheel,Dt=r.onEnable,St=r.onDisable,Ze=r.onClick,gt=r.scrollSpeed,je=r.capture,ie=r.allowClicks,ze=r.lockAxis,_e=r.onLockAxis;this.target=l=Ye(l)||At,this.vars=r,c&&(c=we.utils.toArray(c)),n=n||1e-9,i=i||0,y=y||1,gt=gt||1,s=s||"wheel,touch,pointer",h=h!==!1,d||(d=parseFloat(Je.getComputedStyle(lr).lineHeight)||22);var Et,Pe,Ae,F,Q,qe,He,p=this,$e=0,xt=0,Rt=r.passive||!m&&r.passive!==!1,J=Wt(l,Xe),yt=Wt(l,pe),Mt=J(),Bt=yt(),me=~s.indexOf("touch")&&!~s.indexOf("pointer")&&lt[0]==="pointerdown",Tt=Er(l),ee=l.ownerDocument||Pt,nt=[0,0,0],Qe=[0,0,0],bt=0,mr=function(){return bt=Sr()},ae=function(R,q){return(p.event=R)&&c&&ri(R.target,c)||q&&me&&R.pointerType!=="touch"||$&&$(R,q)},Wr=function(){p._vx.reset(),p._vy.reset(),Pe.pause(),x&&x(p)},vt=function(){var R=p.deltaX=qn(nt),q=p.deltaY=qn(Qe),b=Math.abs(R)>=n,N=Math.abs(q)>=n;fe&&(b||N)&&fe(p,R,q,nt,Qe),b&&(ke&&p.deltaX>0&&ke(p),G&&p.deltaX<0&&G(p),Fe&&Fe(p),T&&p.deltaX<0!=$e<0&&T(p),$e=p.deltaX,nt[0]=nt[1]=nt[2]=0),N&&(ye&&p.deltaY>0&&ye(p),S&&p.deltaY<0&&S(p),j&&j(p),ht&&p.deltaY<0!=xt<0&&ht(p),xt=p.deltaY,Qe[0]=Qe[1]=Qe[2]=0),(F||Ae)&&(Ne&&Ne(p),Ae&&(M&&Ae===1&&M(p),L&&L(p),Ae=0),F=!1),qe&&!(qe=!1)&&_e&&_e(p),Q&&(ce(p),Q=!1),Et=0},Qt=function(R,q,b){nt[b]+=R,Qe[b]+=q,p._vx.update(R),p._vy.update(q),h?Et||(Et=requestAnimationFrame(vt)):vt()},er=function(R,q){ze&&!He&&(p.axis=He=Math.abs(R)>Math.abs(q)?"x":"y",qe=!0),He!=="y"&&(nt[2]+=R,p._vx.update(R,!0)),He!=="x"&&(Qe[2]+=q,p._vy.update(q,!0)),h?Et||(Et=requestAnimationFrame(vt)):vt()},Lt=function(R){if(!ae(R,1)){R=yr(R,m);var q=R.clientX,b=R.clientY,N=q-p.x,C=b-p.y,z=p.isDragging;p.x=q,p.y=b,(z||(N||C)&&(Math.abs(p.startX-q)>=i||Math.abs(p.startY-b)>=i))&&(Ae||(Ae=z?2:1),z||(p.isDragging=!0),er(N,C))}},It=p.onPress=function(P){ae(P,1)||P&&P.button||(p.axis=He=null,Pe.pause(),p.isPressed=!0,P=yr(P),$e=xt=0,p.startX=p.x=P.clientX,p.startY=p.y=P.clientY,p._vx.reset(),p._vy.reset(),We(ne?l:ee,lt[1],Lt,Rt,!0),p.deltaX=p.deltaY=0,X&&X(p))},B=p.onRelease=function(P){if(!ae(P,1)){Oe(ne?l:ee,lt[1],Lt,!0);var R=!isNaN(p.y-p.startY),q=p.isDragging,b=q&&(Math.abs(p.x-p.startX)>3||Math.abs(p.y-p.startY)>3),N=yr(P);!b&&R&&(p._vx.reset(),p._vy.reset(),m&&ie&&we.delayedCall(.08,function(){if(Sr()-bt>300&&!P.defaultPrevented){if(P.target.click)P.target.click();else if(ee.createEvent){var C=ee.createEvent("MouseEvents");C.initMouseEvent("click",!0,!0,Je,1,N.screenX,N.screenY,N.clientX,N.clientY,!1,!1,!1,!1,0,null),P.target.dispatchEvent(C)}}})),p.isDragging=p.isGesturing=p.isPressed=!1,x&&q&&!ne&&Pe.restart(!0),Ae&&vt(),v&&q&&v(p),E&&E(p,b)}},Xt=function(R){return R.touches&&R.touches.length>1&&(p.isGesturing=!0)&&oe(R,p.isDragging)},ot=function(){return(p.isGesturing=!1)||u(p)},it=function(R){if(!ae(R)){var q=J(),b=yt();Qt((q-Mt)*gt,(b-Bt)*gt,1),Mt=q,Bt=b,x&&Pe.restart(!0)}},at=function(R){if(!ae(R)){R=yr(R,m),ce&&(Q=!0);var q=(R.deltaMode===1?d:R.deltaMode===2?Je.innerHeight:1)*y;Qt(R.deltaX*q,R.deltaY*q,0),x&&!ne&&Pe.restart(!0)}},Ft=function(R){if(!ae(R)){var q=R.clientX,b=R.clientY,N=q-p.x,C=b-p.y;p.x=q,p.y=b,F=!0,x&&Pe.restart(!0),(N||C)&&er(N,C)}},tr=function(R){p.event=R,le(p)},wt=function(R){p.event=R,Le(p)},hr=function(R){return ae(R)||yr(R,m)&&Ze(p)};Pe=p._dc=we.delayedCall(g||.25,Wr).pause(),p.deltaX=p.deltaY=0,p._vx=jn(0,50,!0),p._vy=jn(0,50,!0),p.scrollX=J,p.scrollY=yt,p.isDragging=p.isGesturing=p.isPressed=!1,vo(this),p.enable=function(P){return p.isEnabled||(We(Tt?ee:l,"scroll",kn),s.indexOf("scroll")>=0&&We(Tt?ee:l,"scroll",it,Rt,je),s.indexOf("wheel")>=0&&We(l,"wheel",at,Rt,je),(s.indexOf("touch")>=0&&yo||s.indexOf("pointer")>=0)&&(We(l,lt[0],It,Rt,je),We(ee,lt[2],B),We(ee,lt[3],B),ie&&We(l,"click",mr,!0,!0),Ze&&We(l,"click",hr),oe&&We(ee,"gesturestart",Xt),u&&We(ee,"gestureend",ot),le&&We(l,Yt+"enter",tr),Le&&We(l,Yt+"leave",wt),Ne&&We(l,Yt+"move",Ft)),p.isEnabled=!0,p.isDragging=p.isGesturing=p.isPressed=F=Ae=!1,p._vx.reset(),p._vy.reset(),Mt=J(),Bt=yt(),P&&P.type&&It(P),Dt&&Dt(p)),p},p.disable=function(){p.isEnabled&&(sr.filter(function(P){return P!==p&&Er(P.target)}).length||Oe(Tt?ee:l,"scroll",kn),p.isPressed&&(p._vx.reset(),p._vy.reset(),Oe(ne?l:ee,lt[1],Lt,!0)),Oe(Tt?ee:l,"scroll",it,je),Oe(l,"wheel",at,je),Oe(l,lt[0],It,je),Oe(ee,lt[2],B),Oe(ee,lt[3],B),Oe(l,"click",mr,!0),Oe(l,"click",hr),Oe(ee,"gesturestart",Xt),Oe(ee,"gestureend",ot),Oe(l,Yt+"enter",tr),Oe(l,Yt+"leave",wt),Oe(l,Yt+"move",Ft),p.isEnabled=p.isPressed=p.isDragging=!1,St&&St(p))},p.kill=p.revert=function(){p.disable();var P=sr.indexOf(p);P>=0&&sr.splice(P,1),jt===p&&(jt=0)},sr.push(p),ne&&Er(l)&&(jt=p),p.enable(k)},ei(a,[{key:"velocityX",get:function(){return this._vx.getVelocity()}},{key:"velocityY",get:function(){return this._vy.getVelocity()}}]),a}();re.version="3.15.0";re.create=function(a){return new re(a)};re.register=_o;re.getAll=function(){return sr.slice()};re.getById=function(a){return sr.filter(function(e){return e.vars.id===a})[0]};wo()&&we.registerPlugin(re);/*!
 * ScrollTrigger 3.15.0
 * https://gsap.com
 *
 * @license Copyright 2008-2026, GreenSock. All rights reserved.
 * Subject to the terms at https://gsap.com/standard-license
 * @author: Jack Doyle, jack@greensock.com
*/var w,ir,W,H,Ke,Y,zn,sn,Ar,Rr,wr,Fr,Re,dn,_n,Be,Yn,Hn,ar,Co,pn,So,De,Cn,Eo,Ro,zt,Sn,Pn,dr,An,Mr,En,fn,qr=1,Me=Date.now,mn=Me(),rt=0,kr=0,$n=function(e,o,r){var n=Ve(e)&&(e.substr(0,6)==="clamp("||e.indexOf("max")>-1);return r["_"+o+"Clamp"]=n,n?e.substr(6,e.length-7):e},Un=function(e,o){return o&&(!Ve(e)||e.substr(0,6)!=="clamp(")?"clamp("+e+")":e},ni=function a(){return kr&&requestAnimationFrame(a)},Gn=function(){return dn=1},Vn=function(){return dn=0},pt=function(e){return e},jr=function(e){return Math.round(e*1e5)/1e5||0},Mo=function(){return typeof window<"u"},To=function(){return w||Mo()&&(w=window.gsap)&&w.registerPlugin&&w},Kt=function(e){return!!~zn.indexOf(e)},Lo=function(e){return(e==="Height"?An:W["inner"+e])||Ke["client"+e]||Y["client"+e]},No=function(e){return Ot(e,"getBoundingClientRect")||(Kt(e)?function(){return nn.width=W.innerWidth,nn.height=An,nn}:function(){return kt(e)})},oi=function(e,o,r){var n=r.d,i=r.d2,s=r.a;return(s=Ot(e,"getBoundingClientRect"))?function(){return s()[n]}:function(){return(o?Lo(i):e["client"+i])||0}},ii=function(e,o){return!o||~mt.indexOf(e)?No(e):function(){return nn}},ft=function(e,o){var r=o.s,n=o.d2,i=o.d,s=o.a;return Math.max(0,(r="scroll"+n)&&(s=Ot(e,r))?s()-No(e)()[i]:Kt(e)?(Ke[r]||Y[r])-Lo(n):e[r]-e["offset"+n])},Yr=function(e,o){for(var r=0;r<ar.length;r+=3)(!o||~o.indexOf(ar[r+1]))&&e(ar[r],ar[r+1],ar[r+2])},Ve=function(e){return typeof e=="string"},Te=function(e){return typeof e=="function"},_r=function(e){return typeof e=="number"},Ht=function(e){return typeof e=="object"},br=function(e,o,r){return e&&e.progress(o?0:1)&&r&&e.pause()},rr=function(e,o,r){if(e.enabled){var n=e._ctx?e._ctx.add(function(){return o(e,r)}):o(e,r);n&&n.totalTime&&(e.callbackAnimation=n)}},nr=Math.abs,zo="left",Po="top",On="right",Wn="bottom",Ut="width",Gt="height",Tr="Right",Lr="Left",Nr="Top",zr="Bottom",se="padding",et="margin",pr="Width",Dn="Height",ue="px",tt=function(e){return W.getComputedStyle(e.nodeType===Node.DOCUMENT_NODE?e.scrollingElement:e)},ai=function(e){var o=tt(e).position;e.style.position=o==="absolute"||o==="fixed"?o:"relative"},Kn=function(e,o){for(var r in o)r in e||(e[r]=o[r]);return e},kt=function(e,o){var r=o&&tt(e)[_n]!=="matrix(1, 0, 0, 1, 0, 0)"&&w.to(e,{x:0,y:0,xPercent:0,yPercent:0,rotation:0,rotationX:0,rotationY:0,scale:1,skewX:0,skewY:0}).progress(1),n=e.getBoundingClientRect?e.getBoundingClientRect():e.scrollingElement.getBoundingClientRect();return r&&r.progress(0).kill(),n},ln=function(e,o){var r=o.d2;return e["offset"+r]||e["client"+r]||0},Ao=function(e){var o=[],r=e.labels,n=e.duration(),i;for(i in r)o.push(r[i]/n);return o},si=function(e){return function(o){return w.utils.snap(Ao(e),o)}},Bn=function(e){var o=w.utils.snap(e),r=Array.isArray(e)&&e.slice(0).sort(function(n,i){return n-i});return r?function(n,i,s){s===void 0&&(s=.001);var l;if(!i)return o(n);if(i>0){for(n-=s,l=0;l<r.length;l++)if(r[l]>=n)return r[l];return r[l-1]}else for(l=r.length,n+=s;l--;)if(r[l]<=n)return r[l];return r[0]}:function(n,i,s){s===void 0&&(s=.001);var l=o(n);return!i||Math.abs(l-n)<s||l-n<0==i<0?l:o(i<0?n-e:n+e)}},li=function(e){return function(o,r){return Bn(Ao(e))(o,r.direction)}},Hr=function(e,o,r,n){return r.split(",").forEach(function(i){return e(o,i,n)})},xe=function(e,o,r,n,i){return e.addEventListener(o,r,{passive:!n,capture:!!i})},ge=function(e,o,r,n){return e.removeEventListener(o,r,!!n)},$r=function(e,o,r){r=r&&r.wheelHandler,r&&(e(o,"wheel",r),e(o,"touchmove",r))},Jn={startColor:"green",endColor:"red",indent:0,fontSize:"16px",fontWeight:"normal"},Ur={toggleActions:"play",anticipatePin:0},cn={top:0,left:0,center:.5,bottom:1,right:1},Qr=function(e,o){if(Ve(e)){var r=e.indexOf("="),n=~r?+(e.charAt(r-1)+1)*parseFloat(e.substr(r+1)):0;~r&&(e.indexOf("%")>r&&(n*=o/100),e=e.substr(0,r-1)),e=n+(e in cn?cn[e]*o:~e.indexOf("%")?parseFloat(e)*o/100:parseFloat(e)||0)}return e},Gr=function(e,o,r,n,i,s,l,d){var h=i.startColor,m=i.endColor,x=i.fontSize,g=i.indent,c=i.fontWeight,y=H.createElement("div"),k=Kt(r)||Ot(r,"pinType")==="fixed",M=e.indexOf("scroller")!==-1,v=k?Y:r.tagName==="IFRAME"?r.contentDocument.body:r,L=e.indexOf("start")!==-1,X=L?h:m,E="border-color:"+X+";font-size:"+x+";color:"+X+";font-weight:"+c+";pointer-events:none;white-space:nowrap;font-family:sans-serif,Arial;z-index:1000;padding:4px 8px;border-width:0;border-style:solid;";return E+="position:"+((M||d)&&k?"fixed;":"absolute;"),(M||d||!k)&&(E+=(n===pe?On:Wn)+":"+(s+parseFloat(g))+"px;"),l&&(E+="box-sizing:border-box;text-align:left;width:"+l.offsetWidth+"px;"),y._isStart=L,y.setAttribute("class","gsap-marker-"+e+(o?" marker-"+o:"")),y.style.cssText=E,y.innerText=o||o===0?e+"-"+o:e,v.children[0]?v.insertBefore(y,v.children[0]):v.appendChild(y),y._offset=y["offset"+n.op.d2],en(y,0,n,L),y},en=function(e,o,r,n){var i={display:"block"},s=r[n?"os2":"p2"],l=r[n?"p2":"os2"];e._isFlipped=n,i[r.a+"Percent"]=n?-100:0,i[r.a]=n?"1px":0,i["border"+s+pr]=1,i["border"+l+pr]=0,i[r.p]=o+"px",w.set(e,i)},O=[],Rn={},Or,Zn=function(){return Me()-rt>34&&(Or||(Or=requestAnimationFrame(_t)))},or=function(){(!De||!De.isPressed||De.startX>Y.clientWidth)&&(D.cache++,De?Or||(Or=requestAnimationFrame(_t)):_t(),rt||Zt("scrollStart"),rt=Me())},hn=function(){Ro=W.innerWidth,Eo=W.innerHeight},Cr=function(e){D.cache++,(e===!0||!Re&&!So&&!H.fullscreenElement&&!H.webkitFullscreenElement&&(!Cn||Ro!==W.innerWidth||Math.abs(W.innerHeight-Eo)>W.innerHeight*.25))&&sn.restart(!0)},Jt={},ci=[],Oo=function a(){return ge(A,"scrollEnd",a)||$t(!0)},Zt=function(e){return Jt[e]&&Jt[e].map(function(o){return o()})||ci},Ge=[],Wo=function(e){for(var o=0;o<Ge.length;o+=5)(!e||Ge[o+4]&&Ge[o+4].query===e)&&(Ge[o].style.cssText=Ge[o+1],Ge[o].getBBox&&Ge[o].setAttribute("transform",Ge[o+2]||""),Ge[o+3].uncache=1)},Do=function(){return D.forEach(function(e){return Te(e)&&++e.cacheID&&(e.rec=e())})},In=function(e,o){var r;for(Be=0;Be<O.length;Be++)r=O[Be],r&&(!o||r._ctx===o)&&(e?r.kill(1):r.revert(!0,!0));Mr=!0,o&&Wo(o),o||Zt("revert")},Bo=function(e,o){D.cache++,(o||!Ie)&&D.forEach(function(r){return Te(r)&&r.cacheID++&&(r.rec=0)}),Ve(e)&&(W.history.scrollRestoration=Pn=e)},Ie,Vt=0,Qn,di=function(){if(Qn!==Vt){var e=Qn=Vt;requestAnimationFrame(function(){return e===Vt&&$t(!0)})}},Io=function(){Y.appendChild(dr),An=!De&&dr.offsetHeight||W.innerHeight,Y.removeChild(dr)},eo=function(e){return Ar(".gsap-marker-start, .gsap-marker-end, .gsap-marker-scroller-start, .gsap-marker-scroller-end").forEach(function(o){return o.style.display=e?"none":"block"})},$t=function(e,o){if(Ke=H.documentElement,Y=H.body,zn=[W,H,Ke,Y],rt&&!e&&!Mr){xe(A,"scrollEnd",Oo);return}Io(),Ie=A.isRefreshing=!0,Mr||Do();var r=Zt("refreshInit");Co&&A.sort(),o||In(),D.forEach(function(n){Te(n)&&(n.smooth&&(n.target.style.scrollBehavior="auto"),n(0))}),O.slice(0).forEach(function(n){return n.refresh()}),Mr=!1,O.forEach(function(n){if(n._subPinOffset&&n.pin){var i=n.vars.horizontal?"offsetWidth":"offsetHeight",s=n.pin[i];n.revert(!0,1),n.adjustPinSpacing(n.pin[i]-s),n.refresh()}}),En=1,eo(!0),O.forEach(function(n){var i=ft(n.scroller,n._dir),s=n.vars.end==="max"||n._endClamp&&n.end>i,l=n._startClamp&&n.start>=i;(s||l)&&n.setPositions(l?i-1:n.start,s?Math.max(l?i:n.start+1,i):n.end,!0)}),eo(!1),En=0,r.forEach(function(n){return n&&n.render&&n.render(-1)}),D.forEach(function(n){Te(n)&&(n.smooth&&requestAnimationFrame(function(){return n.target.style.scrollBehavior="smooth"}),n.rec&&n(n.rec))}),Bo(Pn,1),sn.pause(),Vt++,Ie=2,_t(2),O.forEach(function(n){return Te(n.vars.onRefresh)&&n.vars.onRefresh(n)}),Ie=A.isRefreshing=!1,Zt("refresh")},Mn=0,tn=1,Pr,_t=function(e){if(e===2||!Ie&&!Mr){A.isUpdating=!0,Pr&&Pr.update(0);var o=O.length,r=Me(),n=r-mn>=50,i=o&&O[0].scroll();if(tn=Mn>i?-1:1,Ie||(Mn=i),n&&(rt&&!dn&&r-rt>200&&(rt=0,Zt("scrollEnd")),wr=mn,mn=r),tn<0){for(Be=o;Be-- >0;)O[Be]&&O[Be].update(0,n);tn=1}else for(Be=0;Be<o;Be++)O[Be]&&O[Be].update(0,n);A.isUpdating=!1}Or=0},Tn=[zo,Po,Wn,On,et+zr,et+Tr,et+Nr,et+Lr,"display","flexShrink","float","zIndex","gridColumnStart","gridColumnEnd","gridRowStart","gridRowEnd","gridArea","justifySelf","alignSelf","placeSelf","order"],rn=Tn.concat([Ut,Gt,"boxSizing","max"+pr,"max"+Dn,"position",et,se,se+Nr,se+Tr,se+zr,se+Lr]),ui=function(e,o,r){ur(r);var n=e._gsap;if(n.spacerIsNative)ur(n.spacerState);else if(e._gsap.swappedIn){var i=o.parentNode;i&&(i.insertBefore(e,o),i.removeChild(o))}e._gsap.swappedIn=!1},gn=function(e,o,r,n){if(!e._gsap.swappedIn){for(var i=Tn.length,s=o.style,l=e.style,d;i--;)d=Tn[i],s[d]=r[d];s.position=r.position==="absolute"?"absolute":"relative",r.display==="inline"&&(s.display="inline-block"),l[Wn]=l[On]="auto",s.flexBasis=r.flexBasis||"auto",s.overflow="visible",s.boxSizing="border-box",s[Ut]=ln(e,Xe)+ue,s[Gt]=ln(e,pe)+ue,s[se]=l[et]=l[Po]=l[zo]="0",ur(n),l[Ut]=l["max"+pr]=r[Ut],l[Gt]=l["max"+Dn]=r[Gt],l[se]=r[se],e.parentNode!==o&&(e.parentNode.insertBefore(o,e),o.appendChild(e)),e._gsap.swappedIn=!0}},pi=/([A-Z])/g,ur=function(e){if(e){var o=e.t.style,r=e.length,n=0,i,s;for((e.t._gsap||w.core.getCache(e.t)).uncache=1;n<r;n+=2)s=e[n+1],i=e[n],s?o[i]=s:o[i]&&o.removeProperty(i.replace(pi,"-$1").toLowerCase())}},Vr=function(e){for(var o=rn.length,r=e.style,n=[],i=0;i<o;i++)n.push(rn[i],r[rn[i]]);return n.t=e,n},fi=function(e,o,r){for(var n=[],i=e.length,s=r?8:0,l;s<i;s+=2)l=e[s],n.push(l,l in o?o[l]:e[s+1]);return n.t=e.t,n},nn={left:0,top:0},to=function(e,o,r,n,i,s,l,d,h,m,x,g,c,y){Te(e)&&(e=e(d)),Ve(e)&&e.substr(0,3)==="max"&&(e=g+(e.charAt(4)==="="?Qr("0"+e.substr(3),r):0));var k=c?c.time():0,M,v,L;if(c&&c.seek(0),isNaN(e)||(e=+e),_r(e))c&&(e=w.utils.mapRange(c.scrollTrigger.start,c.scrollTrigger.end,0,g,e)),l&&en(l,r,n,!0);else{Te(o)&&(o=o(d));var X=(e||"0").split(" "),E,ke,G,S;L=Ye(o,d)||Y,E=kt(L)||{},(!E||!E.left&&!E.top)&&tt(L).display==="none"&&(S=L.style.display,L.style.display="block",E=kt(L),S?L.style.display=S:L.style.removeProperty("display")),ke=Qr(X[0],E[n.d]),G=Qr(X[1]||"0",r),e=E[n.p]-h[n.p]-m+ke+i-G,l&&en(l,G,n,r-G<20||l._isStart&&G>20),r-=r-G}if(y&&(d[y]=e||-.001,e<0&&(e=0)),s){var ye=e+r,Fe=s._isStart;M="scroll"+n.d2,en(s,ye,n,Fe&&ye>20||!Fe&&(x?Math.max(Y[M],Ke[M]):s.parentNode[M])<=ye+1),x&&(h=kt(l),x&&(s.style[n.op.p]=h[n.op.p]-n.op.m-s._offset+ue))}return c&&L&&(M=kt(L),c.seek(g),v=kt(L),c._caScrollDist=M[n.p]-v[n.p],e=e/c._caScrollDist*g),c&&c.seek(k),c?e:Math.round(e)},mi=/(webkit|moz|length|cssText|inset)/i,ro=function(e,o,r,n){if(e.parentNode!==o){var i=e.style,s,l;if(o===Y){e._stOrig=i.cssText,l=tt(e);for(s in l)!+s&&!mi.test(s)&&l[s]&&typeof i[s]=="string"&&s!=="0"&&(i[s]=l[s]);i.top=r,i.left=n}else i.cssText=e._stOrig;w.core.getCache(e).uncache=1,o.appendChild(e)}},Xo=function(e,o,r){var n=o,i=n;return function(s){var l=Math.round(e());return l!==n&&l!==i&&Math.abs(l-n)>3&&Math.abs(l-i)>3&&(s=l,r&&r()),i=n,n=Math.round(s),n}},Kr=function(e,o,r){var n={};n[o.p]="+="+r,w.set(e,n)},no=function(e,o){var r=Wt(e,o),n="_scroll"+o.p2,i=function s(l,d,h,m,x){var g=s.tween,c=d.onComplete,y={};h=h||r();var k=Xo(r,h,function(){g.kill(),s.tween=0});return x=m&&x||0,m=m||l-h,g&&g.kill(),d[n]=l,d.inherit=!1,d.modifiers=y,y[n]=function(){return k(h+m*g.ratio+x*g.ratio*g.ratio)},d.onUpdate=function(){D.cache++,s.tween&&_t()},d.onComplete=function(){s.tween=0,c&&c.call(g)},g=s.tween=w.to(e,d),g};return e[n]=r,r.wheelHandler=function(){return i.tween&&i.tween.kill()&&(i.tween=0)},xe(e,"wheel",r.wheelHandler),A.isTouch&&xe(e,"touchmove",r.wheelHandler),i},A=function(){function a(o,r){ir||a.register(w)||console.warn("Please gsap.registerPlugin(ScrollTrigger)"),Sn(this),this.init(o,r)}var e=a.prototype;return e.init=function(r,n){if(this.progress=this.start=0,this.vars&&this.kill(!0,!0),!kr){this.update=this.refresh=this.kill=pt;return}r=Kn(Ve(r)||_r(r)||r.nodeType?{trigger:r}:r,Ur);var i=r,s=i.onUpdate,l=i.toggleClass,d=i.id,h=i.onToggle,m=i.onRefresh,x=i.scrub,g=i.trigger,c=i.pin,y=i.pinSpacing,k=i.invalidateOnRefresh,M=i.anticipatePin,v=i.onScrubComplete,L=i.onSnapComplete,X=i.once,E=i.snap,ke=i.pinReparent,G=i.pinSpacer,S=i.containerAnimation,ye=i.fastScrollEnd,Fe=i.preventOverlaps,j=r.horizontal||r.containerAnimation&&r.horizontal!==!1?Xe:pe,fe=!x&&x!==0,T=Ye(r.scroller||W),ht=w.core.getCache(T),le=Kt(T),Le=("pinType"in r?r.pinType:Ot(T,"pinType")||le&&"fixed")==="fixed",Ne=[r.onEnter,r.onLeave,r.onEnterBack,r.onLeaveBack],$=fe&&r.toggleActions.split(" "),ne="markers"in r?r.markers:Ur.markers,oe=le?0:parseFloat(tt(T)["border"+j.p2+pr])||0,u=this,ce=r.onRefreshInit&&function(){return r.onRefreshInit(u)},Dt=oi(T,le,j),St=ii(T,le),Ze=0,gt=0,je=0,ie=Wt(T,j),ze,_e,Et,Pe,Ae,F,Q,qe,He,p,$e,xt,Rt,J,yt,Mt,Bt,me,Tt,ee,nt,Qe,bt,mr,ae,Wr,vt,Qt,er,Lt,It,B,Xt,ot,it,at,Ft,tr,wt;if(u._startClamp=u._endClamp=!1,u._dir=j,M*=45,u.scroller=T,u.scroll=S?S.time.bind(S):ie,Pe=ie(),u.vars=r,n=n||r.animation,"refreshPriority"in r&&(Co=1,r.refreshPriority===-9999&&(Pr=u)),ht.tweenScroll=ht.tweenScroll||{top:no(T,pe),left:no(T,Xe)},u.tweenTo=ze=ht.tweenScroll[j.p],u.scrubDuration=function(b){Xt=_r(b)&&b,Xt?B?B.duration(b):B=w.to(n,{ease:"expo",totalProgress:"+=0",inherit:!1,duration:Xt,paused:!0,onComplete:function(){return v&&v(u)}}):(B&&B.progress(1).kill(),B=0)},n&&(n.vars.lazy=!1,n._initted&&!u.isReverted||n.vars.immediateRender!==!1&&r.immediateRender!==!1&&n.duration()&&n.render(0,!0,!0),u.animation=n.pause(),n.scrollTrigger=u,u.scrubDuration(x),Lt=0,d||(d=n.vars.id)),E&&((!Ht(E)||E.push)&&(E={snapTo:E}),"scrollBehavior"in Y.style&&w.set(le?[Y,Ke]:T,{scrollBehavior:"auto"}),D.forEach(function(b){return Te(b)&&b.target===(le?H.scrollingElement||Ke:T)&&(b.smooth=!1)}),Et=Te(E.snapTo)?E.snapTo:E.snapTo==="labels"?si(n):E.snapTo==="labelsDirectional"?li(n):E.directional!==!1?function(b,N){return Bn(E.snapTo)(b,Me()-gt<500?0:N.direction)}:w.utils.snap(E.snapTo),ot=E.duration||{min:.1,max:2},ot=Ht(ot)?Rr(ot.min,ot.max):Rr(ot,ot),it=w.delayedCall(E.delay||Xt/2||.1,function(){var b=ie(),N=Me()-gt<500,C=ze.tween;if((N||Math.abs(u.getVelocity())<10)&&!C&&!dn&&Ze!==b){var z=(b-F)/J,he=n&&!fe?n.totalProgress():z,I=N?0:(he-It)/(Me()-wr)*1e3||0,te=w.utils.clamp(-z,1-z,nr(I/2)*I/.185),Ce=z+(E.inertia===!1?0:te),Z,V,U=E,st=U.onStart,K=U.onInterrupt,Ue=U.onComplete;if(Z=Et(Ce,u),_r(Z)||(Z=Ce),V=Math.max(0,Math.round(F+Z*J)),b<=Q&&b>=F&&V!==b){if(C&&!C._initted&&C.data<=nr(V-b))return;E.inertia===!1&&(te=Z-z),ze(V,{duration:ot(nr(Math.max(nr(Ce-he),nr(Z-he))*.185/I/.05||0)),ease:E.ease||"power3",data:nr(V-b),onInterrupt:function(){return it.restart(!0)&&K&&rr(u,K)},onComplete:function(){u.update(),Ze=ie(),n&&!fe&&(B?B.resetTo("totalProgress",Z,n._tTime/n._tDur):n.progress(Z)),Lt=It=n&&!fe?n.totalProgress():u.progress,L&&L(u),Ue&&rr(u,Ue)}},b,te*J,V-b-te*J),st&&rr(u,st,ze.tween)}}else u.isActive&&Ze!==b&&it.restart(!0)}).pause()),d&&(Rn[d]=u),g=u.trigger=Ye(g||c!==!0&&c),wt=g&&g._gsap&&g._gsap.stRevert,wt&&(wt=wt(u)),c=c===!0?g:Ye(c),Ve(l)&&(l={targets:g,className:l}),c&&(y===!1||y===et||(y=!y&&c.parentNode&&c.parentNode.style&&tt(c.parentNode).display==="flex"?!1:se),u.pin=c,_e=w.core.getCache(c),_e.spacer?yt=_e.pinState:(G&&(G=Ye(G),G&&!G.nodeType&&(G=G.current||G.nativeElement),_e.spacerIsNative=!!G,G&&(_e.spacerState=Vr(G))),_e.spacer=me=G||H.createElement("div"),me.classList.add("pin-spacer"),d&&me.classList.add("pin-spacer-"+d),_e.pinState=yt=Vr(c)),r.force3D!==!1&&w.set(c,{force3D:!0}),u.spacer=me=_e.spacer,er=tt(c),mr=er[y+j.os2],ee=w.getProperty(c),nt=w.quickSetter(c,j.a,ue),gn(c,me,er),Bt=Vr(c)),ne){xt=Ht(ne)?Kn(ne,Jn):Jn,p=Gr("scroller-start",d,T,j,xt,0),$e=Gr("scroller-end",d,T,j,xt,0,p),Tt=p["offset"+j.op.d2];var hr=Ye(Ot(T,"content")||T);qe=this.markerStart=Gr("start",d,hr,j,xt,Tt,0,S),He=this.markerEnd=Gr("end",d,hr,j,xt,Tt,0,S),S&&(tr=w.quickSetter([qe,He],j.a,ue)),!Le&&!(mt.length&&Ot(T,"fixedMarkers")===!0)&&(ai(le?Y:T),w.set([p,$e],{force3D:!0}),Wr=w.quickSetter(p,j.a,ue),Qt=w.quickSetter($e,j.a,ue))}if(S){var P=S.vars.onUpdate,R=S.vars.onUpdateParams;S.eventCallback("onUpdate",function(){u.update(0,0,1),P&&P.apply(S,R||[])})}if(u.previous=function(){return O[O.indexOf(u)-1]},u.next=function(){return O[O.indexOf(u)+1]},u.revert=function(b,N){if(!N)return u.kill(!0);var C=b!==!1||!u.enabled,z=Re;C!==u.isReverted&&(C&&(at=Math.max(ie(),u.scroll.rec||0),je=u.progress,Ft=n&&n.progress()),qe&&[qe,He,p,$e].forEach(function(he){return he.style.display=C?"none":"block"}),C&&(Re=u,u.update(C)),c&&(!ke||!u.isActive)&&(C?ui(c,me,yt):gn(c,me,tt(c),ae)),C||u.update(C),Re=z,u.isReverted=C)},u.refresh=function(b,N,C,z){if(!((Re||!u.enabled)&&!N)){if(c&&b&&rt){xe(a,"scrollEnd",Oo);return}!Ie&&ce&&ce(u),Re=u,ze.tween&&!C&&(ze.tween.kill(),ze.tween=0),B&&B.pause(),k&&n&&(n.revert({kill:!1}).invalidate(),n.getChildren?n.getChildren(!0,!0,!1).forEach(function(Nt){return Nt.vars.immediateRender&&Nt.render(0,!0,!0)}):n.vars.immediateRender&&n.render(0,!0,!0)),u.isReverted||u.revert(!0,!0),u._subPinOffset=!1;var he=Dt(),I=St(),te=S?S.duration():ft(T,j),Ce=J<=.01||!J,Z=0,V=z||0,U=Ht(C)?C.end:r.end,st=r.endTrigger||g,K=Ht(C)?C.start:r.start||(r.start===0||!g?0:c?"0 0":"0 100%"),Ue=u.pinnedContainer=r.pinnedContainer&&Ye(r.pinnedContainer,u),ct=g&&Math.max(0,O.indexOf(u))||0,be=ct,ve,Se,qt,Dr,Ee,de,dt,un,Fn,gr,ut,xr,Br;for(ne&&Ht(C)&&(xr=w.getProperty(p,j.p),Br=w.getProperty($e,j.p));be-- >0;)de=O[be],de.end||de.refresh(0,1)||(Re=u),dt=de.pin,dt&&(dt===g||dt===c||dt===Ue)&&!de.isReverted&&(gr||(gr=[]),gr.unshift(de),de.revert(!0,!0)),de!==O[be]&&(ct--,be--);for(Te(K)&&(K=K(u)),K=$n(K,"start",u),F=to(K,g,he,j,ie(),qe,p,u,I,oe,Le,te,S,u._startClamp&&"_startClamp")||(c?-.001:0),Te(U)&&(U=U(u)),Ve(U)&&!U.indexOf("+=")&&(~U.indexOf(" ")?U=(Ve(K)?K.split(" ")[0]:"")+U:(Z=Qr(U.substr(2),he),U=Ve(K)?K:(S?w.utils.mapRange(0,S.duration(),S.scrollTrigger.start,S.scrollTrigger.end,F):F)+Z,st=g)),U=$n(U,"end",u),Q=Math.max(F,to(U||(st?"100% 0":te),st,he,j,ie()+Z,He,$e,u,I,oe,Le,te,S,u._endClamp&&"_endClamp"))||-.001,Z=0,be=ct;be--;)de=O[be]||{},dt=de.pin,dt&&de.start-de._pinPush<=F&&!S&&de.end>0&&(ve=de.end-(u._startClamp?Math.max(0,de.start):de.start),(dt===g&&de.start-de._pinPush<F||dt===Ue)&&isNaN(K)&&(Z+=ve*(1-de.progress)),dt===c&&(V+=ve));if(F+=Z,Q+=Z,u._startClamp&&(u._startClamp+=Z),u._endClamp&&!Ie&&(u._endClamp=Q||-.001,Q=Math.min(Q,ft(T,j))),J=Q-F||(F-=.01)&&.001,Ce&&(je=w.utils.clamp(0,1,w.utils.normalize(F,Q,at))),u._pinPush=V,qe&&Z&&(ve={},ve[j.a]="+="+Z,Ue&&(ve[j.p]="-="+ie()),w.set([qe,He],ve)),c&&!(En&&u.end>=ft(T,j)))ve=tt(c),Dr=j===pe,qt=ie(),Qe=parseFloat(ee(j.a))+V,!te&&Q>1&&(ut=(le?H.scrollingElement||Ke:T).style,ut={style:ut,value:ut["overflow"+j.a.toUpperCase()]},le&&tt(Y)["overflow"+j.a.toUpperCase()]!=="scroll"&&(ut.style["overflow"+j.a.toUpperCase()]="scroll")),gn(c,me,ve),Bt=Vr(c),Se=kt(c,!0),un=Le&&Wt(T,Dr?Xe:pe)(),y?(ae=[y+j.os2,J+V+ue],ae.t=me,be=y===se?ln(c,j)+J+V:0,be&&(ae.push(j.d,be+ue),me.style.flexBasis!=="auto"&&(me.style.flexBasis=be+ue)),ur(ae),Ue&&O.forEach(function(Nt){Nt.pin===Ue&&Nt.vars.pinSpacing!==!1&&(Nt._subPinOffset=!0)}),Le&&ie(at)):(be=ln(c,j),be&&me.style.flexBasis!=="auto"&&(me.style.flexBasis=be+ue)),Le&&(Ee={top:Se.top+(Dr?qt-F:un)+ue,left:Se.left+(Dr?un:qt-F)+ue,boxSizing:"border-box",position:"fixed"},Ee[Ut]=Ee["max"+pr]=Math.ceil(Se.width)+ue,Ee[Gt]=Ee["max"+Dn]=Math.ceil(Se.height)+ue,Ee[et]=Ee[et+Nr]=Ee[et+Tr]=Ee[et+zr]=Ee[et+Lr]="0",Ee[se]=ve[se],Ee[se+Nr]=ve[se+Nr],Ee[se+Tr]=ve[se+Tr],Ee[se+zr]=ve[se+zr],Ee[se+Lr]=ve[se+Lr],Mt=fi(yt,Ee,ke),Ie&&ie(0)),n?(Fn=n._initted,pn(1),n.render(n.duration(),!0,!0),bt=ee(j.a)-Qe+J+V,vt=Math.abs(J-bt)>1,Le&&vt&&Mt.splice(Mt.length-2,2),n.render(0,!0,!0),Fn||n.invalidate(!0),n.parent||n.totalTime(n.totalTime()),pn(0)):bt=J,ut&&(ut.value?ut.style["overflow"+j.a.toUpperCase()]=ut.value:ut.style.removeProperty("overflow-"+j.a));else if(g&&ie()&&!S)for(Se=g.parentNode;Se&&Se!==Y;)Se._pinOffset&&(F-=Se._pinOffset,Q-=Se._pinOffset),Se=Se.parentNode;gr&&gr.forEach(function(Nt){return Nt.revert(!1,!0)}),u.start=F,u.end=Q,Pe=Ae=Ie?at:ie(),!S&&!Ie&&(Pe<at&&ie(at),u.scroll.rec=0),u.revert(!1,!0),gt=Me(),it&&(Ze=-1,it.restart(!0)),Re=0,n&&fe&&(n._initted||Ft)&&n.progress()!==Ft&&n.progress(Ft||0,!0).render(n.time(),!0,!0),(Ce||je!==u.progress||S||k||n&&!n._initted)&&(n&&!fe&&(n._initted||je||n.vars.immediateRender!==!1)&&n.totalProgress(S&&F<-.001&&!je?w.utils.normalize(F,Q,0):je,!0),u.progress=Ce||(Pe-F)/J===je?0:je),c&&y&&(me._pinOffset=Math.round(u.progress*bt)),B&&B.invalidate(),isNaN(xr)||(xr-=w.getProperty(p,j.p),Br-=w.getProperty($e,j.p),Kr(p,j,xr),Kr(qe,j,xr-(z||0)),Kr($e,j,Br),Kr(He,j,Br-(z||0))),Ce&&!Ie&&u.update(),m&&!Ie&&!Rt&&(Rt=!0,m(u),Rt=!1)}},u.getVelocity=function(){return(ie()-Ae)/(Me()-wr)*1e3||0},u.endAnimation=function(){br(u.callbackAnimation),n&&(B?B.progress(1):n.paused()?fe||br(n,u.direction<0,1):br(n,n.reversed()))},u.labelToScroll=function(b){return n&&n.labels&&(F||u.refresh()||F)+n.labels[b]/n.duration()*J||0},u.getTrailing=function(b){var N=O.indexOf(u),C=u.direction>0?O.slice(0,N).reverse():O.slice(N+1);return(Ve(b)?C.filter(function(z){return z.vars.preventOverlaps===b}):C).filter(function(z){return u.direction>0?z.end<=F:z.start>=Q})},u.update=function(b,N,C){if(!(S&&!C&&!b)){var z=Ie===!0?at:u.scroll(),he=b?0:(z-F)/J,I=he<0?0:he>1?1:he||0,te=u.progress,Ce,Z,V,U,st,K,Ue,ct;if(N&&(Ae=Pe,Pe=S?ie():z,E&&(It=Lt,Lt=n&&!fe?n.totalProgress():I)),M&&c&&!Re&&!qr&&rt&&(!I&&F<z+(z-Ae)/(Me()-wr)*M?I=1e-4:I===1&&Q>z+(z-Ae)/(Me()-wr)*M&&(I=.9999)),I!==te&&u.enabled){if(Ce=u.isActive=!!I&&I<1,Z=!!te&&te<1,K=Ce!==Z,st=K||!!I!=!!te,u.direction=I>te?1:-1,u.progress=I,st&&!Re&&(V=I&&!te?0:I===1?1:te===1?2:3,fe&&(U=!K&&$[V+1]!=="none"&&$[V+1]||$[V],ct=n&&(U==="complete"||U==="reset"||U in n))),Fe&&(K||ct)&&(ct||x||!n)&&(Te(Fe)?Fe(u):u.getTrailing(Fe).forEach(function(qt){return qt.endAnimation()})),fe||(B&&!Re&&!qr?(B._dp._time-B._start!==B._time&&B.render(B._dp._time-B._start),B.resetTo?B.resetTo("totalProgress",I,n._tTime/n._tDur):(B.vars.totalProgress=I,B.invalidate().restart())):n&&n.totalProgress(I,!!(Re&&(gt||b)))),c){if(b&&y&&(me.style[y+j.os2]=mr),!Le)nt(jr(Qe+bt*I));else if(st){if(Ue=!b&&I>te&&Q+1>z&&z+1>=ft(T,j),ke)if(!b&&(Ce||Ue)){var be=kt(c,!0),ve=z-F;ro(c,Y,be.top+(j===pe?ve:0)+ue,be.left+(j===pe?0:ve)+ue)}else ro(c,me);ur(Ce||Ue?Mt:Bt),vt&&I<1&&Ce||nt(Qe+(I===1&&!Ue?bt:0))}}E&&!ze.tween&&!Re&&!qr&&it.restart(!0),l&&(K||X&&I&&(I<1||!fn))&&Ar(l.targets).forEach(function(qt){return qt.classList[Ce||X?"add":"remove"](l.className)}),s&&!fe&&!b&&s(u),st&&!Re?(fe&&(ct&&(U==="complete"?n.pause().totalProgress(1):U==="reset"?n.restart(!0).pause():U==="restart"?n.restart(!0):n[U]()),s&&s(u)),(K||!fn)&&(h&&K&&rr(u,h),Ne[V]&&rr(u,Ne[V]),X&&(I===1?u.kill(!1,1):Ne[V]=0),K||(V=I===1?1:3,Ne[V]&&rr(u,Ne[V]))),ye&&!Ce&&Math.abs(u.getVelocity())>(_r(ye)?ye:2500)&&(br(u.callbackAnimation),B?B.progress(1):br(n,U==="reverse"?1:!I,1))):fe&&s&&!Re&&s(u)}if(Qt){var Se=S?z/S.duration()*(S._caScrollDist||0):z;Wr(Se+(p._isFlipped?1:0)),Qt(Se)}tr&&tr(-z/S.duration()*(S._caScrollDist||0))}},u.enable=function(b,N){u.enabled||(u.enabled=!0,xe(T,"resize",Cr),le||xe(T,"scroll",or),ce&&xe(a,"refreshInit",ce),b!==!1&&(u.progress=je=0,Pe=Ae=Ze=ie()),N!==!1&&u.refresh())},u.getTween=function(b){return b&&ze?ze.tween:B},u.setPositions=function(b,N,C,z){if(S){var he=S.scrollTrigger,I=S.duration(),te=he.end-he.start;b=he.start+te*b/I,N=he.start+te*N/I}u.refresh(!1,!1,{start:Un(b,C&&!!u._startClamp),end:Un(N,C&&!!u._endClamp)},z),u.update()},u.adjustPinSpacing=function(b){if(ae&&b){var N=ae.indexOf(j.d)+1;ae[N]=parseFloat(ae[N])+b+ue,ae[1]=parseFloat(ae[1])+b+ue,ur(ae)}},u.disable=function(b,N){if(b!==!1&&u.revert(!0,!0),u.enabled&&(u.enabled=u.isActive=!1,N||B&&B.pause(),at=0,_e&&(_e.uncache=1),ce&&ge(a,"refreshInit",ce),it&&(it.pause(),ze.tween&&ze.tween.kill()&&(ze.tween=0)),!le)){for(var C=O.length;C--;)if(O[C].scroller===T&&O[C]!==u)return;ge(T,"resize",Cr),le||ge(T,"scroll",or)}},u.kill=function(b,N){u.disable(b,N),B&&!N&&B.kill(),d&&delete Rn[d];var C=O.indexOf(u);C>=0&&O.splice(C,1),C===Be&&tn>0&&Be--,C=0,O.forEach(function(z){return z.scroller===u.scroller&&(C=1)}),C||Ie||(u.scroll.rec=0),n&&(n.scrollTrigger=null,b&&n.revert({kill:!1}),N||n.kill()),qe&&[qe,He,p,$e].forEach(function(z){return z.parentNode&&z.parentNode.removeChild(z)}),Pr===u&&(Pr=0),c&&(_e&&(_e.uncache=1),C=0,O.forEach(function(z){return z.pin===c&&C++}),C||(_e.spacer=0)),r.onKill&&r.onKill(u)},O.push(u),u.enable(!1,!1),wt&&wt(u),n&&n.add&&!J){var q=u.update;u.update=function(){u.update=q,D.cache++,F||Q||u.refresh()},w.delayedCall(.01,u.update),J=.01,F=Q=0}else u.refresh();c&&di()},a.register=function(r){return ir||(w=r||To(),Mo()&&window.document&&a.enable(),ir=kr),ir},a.defaults=function(r){if(r)for(var n in r)Ur[n]=r[n];return Ur},a.disable=function(r,n){kr=0,O.forEach(function(s){return s[n?"kill":"disable"](r)}),ge(W,"wheel",or),ge(H,"scroll",or),clearInterval(Fr),ge(H,"touchcancel",pt),ge(Y,"touchstart",pt),Hr(ge,H,"pointerdown,touchstart,mousedown",Gn),Hr(ge,H,"pointerup,touchend,mouseup",Vn),sn.kill(),Yr(ge);for(var i=0;i<D.length;i+=3)$r(ge,D[i],D[i+1]),$r(ge,D[i],D[i+2])},a.enable=function(){if(W=window,H=document,Ke=H.documentElement,Y=H.body,w){if(Ar=w.utils.toArray,Rr=w.utils.clamp,Sn=w.core.context||pt,pn=w.core.suppressOverwrites||pt,Pn=W.history.scrollRestoration||"auto",Mn=W.pageYOffset||0,w.core.globals("ScrollTrigger",a),Y){kr=1,dr=document.createElement("div"),dr.style.height="100vh",dr.style.position="absolute",Io(),ni(),re.register(w),a.isTouch=re.isTouch,zt=re.isTouch&&/(iPad|iPhone|iPod|Mac)/g.test(navigator.userAgent),Cn=re.isTouch===1,xe(W,"wheel",or),zn=[W,H,Ke,Y],w.matchMedia?(a.matchMedia=function(m){var x=w.matchMedia(),g;for(g in m)x.add(g,m[g]);return x},w.addEventListener("matchMediaInit",function(){Do(),In()}),w.addEventListener("matchMediaRevert",function(){return Wo()}),w.addEventListener("matchMedia",function(){$t(0,1),Zt("matchMedia")}),w.matchMedia().add("(orientation: portrait)",function(){return hn(),hn})):console.warn("Requires GSAP 3.11.0 or later"),hn(),xe(H,"scroll",or);var r=Y.hasAttribute("style"),n=Y.style,i=n.borderTopStyle,s=w.core.Animation.prototype,l,d;for(s.revert||Object.defineProperty(s,"revert",{value:function(){return this.time(-.01,!0)}}),n.borderTopStyle="solid",l=kt(Y),pe.m=Math.round(l.top+pe.sc())||0,Xe.m=Math.round(l.left+Xe.sc())||0,i?n.borderTopStyle=i:n.removeProperty("border-top-style"),r||(Y.setAttribute("style",""),Y.removeAttribute("style")),Fr=setInterval(Zn,250),w.delayedCall(.5,function(){return qr=0}),xe(H,"touchcancel",pt),xe(Y,"touchstart",pt),Hr(xe,H,"pointerdown,touchstart,mousedown",Gn),Hr(xe,H,"pointerup,touchend,mouseup",Vn),_n=w.utils.checkPrefix("transform"),rn.push(_n),ir=Me(),sn=w.delayedCall(.2,$t).pause(),ar=[H,"visibilitychange",function(){var m=W.innerWidth,x=W.innerHeight;H.hidden?(Yn=m,Hn=x):(Yn!==m||Hn!==x)&&Cr()},H,"DOMContentLoaded",$t,W,"load",$t,W,"resize",Cr],Yr(xe),O.forEach(function(m){return m.enable(0,1)}),d=0;d<D.length;d+=3)$r(ge,D[d],D[d+1]),$r(ge,D[d],D[d+2])}else if(H){var h=function m(){a.enable(),H.removeEventListener("DOMContentLoaded",m)};H.addEventListener("DOMContentLoaded",h)}}},a.config=function(r){"limitCallbacks"in r&&(fn=!!r.limitCallbacks);var n=r.syncInterval;n&&clearInterval(Fr)||(Fr=n)&&setInterval(Zn,n),"ignoreMobileResize"in r&&(Cn=a.isTouch===1&&r.ignoreMobileResize),"autoRefreshEvents"in r&&(Yr(ge)||Yr(xe,r.autoRefreshEvents||"none"),So=(r.autoRefreshEvents+"").indexOf("resize")===-1)},a.scrollerProxy=function(r,n){var i=Ye(r),s=D.indexOf(i),l=Kt(i);~s&&D.splice(s,l?6:2),n&&(l?mt.unshift(W,n,Y,n,Ke,n):mt.unshift(i,n))},a.clearMatchMedia=function(r){O.forEach(function(n){return n._ctx&&n._ctx.query===r&&n._ctx.kill(!0,!0)})},a.isInViewport=function(r,n,i){var s=(Ve(r)?Ye(r):r).getBoundingClientRect(),l=s[i?Ut:Gt]*n||0;return i?s.right-l>0&&s.left+l<W.innerWidth:s.bottom-l>0&&s.top+l<W.innerHeight},a.positionInViewport=function(r,n,i){Ve(r)&&(r=Ye(r));var s=r.getBoundingClientRect(),l=s[i?Ut:Gt],d=n==null?l/2:n in cn?cn[n]*l:~n.indexOf("%")?parseFloat(n)*l/100:parseFloat(n)||0;return i?(s.left+d)/W.innerWidth:(s.top+d)/W.innerHeight},a.killAll=function(r){if(O.slice(0).forEach(function(i){return i.vars.id!=="ScrollSmoother"&&i.kill()}),r!==!0){var n=Jt.killAll||[];Jt={},n.forEach(function(i){return i()})}},a}();A.version="3.15.0";A.saveStyles=function(a){return a?Ar(a).forEach(function(e){if(e&&e.style){var o=Ge.indexOf(e);o>=0&&Ge.splice(o,5),Ge.push(e,e.style.cssText,e.getBBox&&e.getAttribute("transform"),w.core.getCache(e),Sn())}}):Ge};A.revert=function(a,e){return In(!a,e)};A.create=function(a,e){return new A(a,e)};A.refresh=function(a){return a?Cr(!0):(ir||A.register())&&$t(!0)};A.update=function(a){return++D.cache&&_t(a===!0?2:0)};A.clearScrollMemory=Bo;A.maxScroll=function(a,e){return ft(a,e?Xe:pe)};A.getScrollFunc=function(a,e){return Wt(Ye(a),e?Xe:pe)};A.getById=function(a){return Rn[a]};A.getAll=function(){return O.filter(function(a){return a.vars.id!=="ScrollSmoother"})};A.isScrolling=function(){return!!rt};A.snapDirectional=Bn;A.addEventListener=function(a,e){var o=Jt[a]||(Jt[a]=[]);~o.indexOf(e)||o.push(e)};A.removeEventListener=function(a,e){var o=Jt[a],r=o&&o.indexOf(e);r>=0&&o.splice(r,1)};A.batch=function(a,e){var o=[],r={},n=e.interval||.016,i=e.batchMax||1e9,s=function(h,m){var x=[],g=[],c=w.delayedCall(n,function(){m(x,g),x=[],g=[]}).pause();return function(y){x.length||c.restart(!0),x.push(y.trigger),g.push(y),i<=x.length&&c.progress(1)}},l;for(l in e)r[l]=l.substr(0,2)==="on"&&Te(e[l])&&l!=="onRefreshInit"?s(l,e[l]):e[l];return Te(i)&&(i=i(),xe(A,"refresh",function(){return i=e.batchMax()})),Ar(a).forEach(function(d){var h={};for(l in r)h[l]=r[l];h.trigger=d,o.push(A.create(h))}),o};var oo=function(e,o,r,n){return o>n?e(n):o<0&&e(0),r>n?(n-o)/(r-o):r<0?o/(o-r):1},xn=function a(e,o){o===!0?e.style.removeProperty("touch-action"):e.style.touchAction=o===!0?"auto":o?"pan-"+o+(re.isTouch?" pinch-zoom":""):"none",e===Ke&&a(Y,o)},Jr={auto:1,scroll:1},hi=function(e){var o=e.event,r=e.target,n=e.axis,i=(o.changedTouches?o.changedTouches[0]:o).target,s=i._gsap||w.core.getCache(i),l=Me(),d;if(!s._isScrollT||l-s._isScrollT>2e3){for(;i&&i!==Y&&(i.scrollHeight<=i.clientHeight&&i.scrollWidth<=i.clientWidth||!(Jr[(d=tt(i)).overflowY]||Jr[d.overflowX]));)i=i.parentNode;s._isScroll=i&&i!==r&&!Kt(i)&&(Jr[(d=tt(i)).overflowY]||Jr[d.overflowX]),s._isScrollT=l}(s._isScroll||n==="x")&&(o.stopPropagation(),o._gsapAllow=!0)},Fo=function(e,o,r,n){return re.create({target:e,capture:!0,debounce:!1,lockAxis:!0,type:o,onWheel:n=n&&hi,onPress:n,onDrag:n,onScroll:n,onEnable:function(){return r&&xe(H,re.eventTypes[0],ao,!1,!0)},onDisable:function(){return ge(H,re.eventTypes[0],ao,!0)}})},gi=/(input|label|select|textarea)/i,io,ao=function(e){var o=gi.test(e.target.tagName);(o||io)&&(e._gsapAllow=!0,io=o)},xi=function(e){Ht(e)||(e={}),e.preventDefault=e.isNormalizer=e.allowClicks=!0,e.type||(e.type="wheel,touch"),e.debounce=!!e.debounce,e.id=e.id||"normalizer";var o=e,r=o.normalizeScrollX,n=o.momentum,i=o.allowNestedScroll,s=o.onRelease,l,d,h=Ye(e.target)||Ke,m=w.core.globals().ScrollSmoother,x=m&&m.get(),g=zt&&(e.content&&Ye(e.content)||x&&e.content!==!1&&!x.smooth()&&x.content()),c=Wt(h,pe),y=Wt(h,Xe),k=1,M=(re.isTouch&&W.visualViewport?W.visualViewport.scale*W.visualViewport.width:W.outerWidth)/W.innerWidth,v=0,L=Te(n)?function(){return n(l)}:function(){return n||2.8},X,E,ke=Fo(h,e.type,!0,i),G=function(){return E=!1},S=pt,ye=pt,Fe=function(){d=ft(h,pe),ye=Rr(zt?1:0,d),r&&(S=Rr(0,ft(h,Xe))),X=Vt},j=function(){g._gsap.y=jr(parseFloat(g._gsap.y)+c.offset)+"px",g.style.transform="matrix3d(1, 0, 0, 0, 0, 1, 0, 0, 0, 0, 1, 0, 0, "+parseFloat(g._gsap.y)+", 0, 1)",c.offset=c.cacheID=0},fe=function(){if(E){requestAnimationFrame(G);var ne=jr(l.deltaY/2),oe=ye(c.v-ne);if(g&&oe!==c.v+c.offset){c.offset=oe-c.v;var u=jr((parseFloat(g&&g._gsap.y)||0)-c.offset);g.style.transform="matrix3d(1, 0, 0, 0, 0, 1, 0, 0, 0, 0, 1, 0, 0, "+u+", 0, 1)",g._gsap.y=u+"px",c.cacheID=D.cache,_t()}return!0}c.offset&&j(),E=!0},T,ht,le,Le,Ne=function(){Fe(),T.isActive()&&T.vars.scrollY>d&&(c()>d?T.progress(1)&&c(d):T.resetTo("scrollY",d))};return g&&w.set(g,{y:"+=0"}),e.ignoreCheck=function($){return zt&&$.type==="touchmove"&&fe()||k>1.05&&$.type!=="touchstart"||l.isGesturing||$.touches&&$.touches.length>1},e.onPress=function(){E=!1;var $=k;k=jr((W.visualViewport&&W.visualViewport.scale||1)/M),T.pause(),$!==k&&xn(h,k>1.01?!0:r?!1:"x"),ht=y(),le=c(),Fe(),X=Vt},e.onRelease=e.onGestureStart=function($,ne){if(c.offset&&j(),!ne)Le.restart(!0);else{D.cache++;var oe=L(),u,ce;r&&(u=y(),ce=u+oe*.05*-$.velocityX/.227,oe*=oo(y,u,ce,ft(h,Xe)),T.vars.scrollX=S(ce)),u=c(),ce=u+oe*.05*-$.velocityY/.227,oe*=oo(c,u,ce,ft(h,pe)),T.vars.scrollY=ye(ce),T.invalidate().duration(oe).play(.01),(zt&&T.vars.scrollY>=d||u>=d-1)&&w.to({},{onUpdate:Ne,duration:oe})}s&&s($)},e.onWheel=function(){T._ts&&T.pause(),Me()-v>1e3&&(X=0,v=Me())},e.onChange=function($,ne,oe,u,ce){if(Vt!==X&&Fe(),ne&&r&&y(S(u[2]===ne?ht+($.startX-$.x):y()+ne-u[1])),oe){c.offset&&j();var Dt=ce[2]===oe,St=Dt?le+$.startY-$.y:c()+oe-ce[1],Ze=ye(St);Dt&&St!==Ze&&(le+=Ze-St),c(Ze)}(oe||ne)&&_t()},e.onEnable=function(){xn(h,r?!1:"x"),A.addEventListener("refresh",Ne),xe(W,"resize",Ne),c.smooth&&(c.target.style.scrollBehavior="auto",c.smooth=y.smooth=!1),ke.enable()},e.onDisable=function(){xn(h,!0),ge(W,"resize",Ne),A.removeEventListener("refresh",Ne),ke.kill()},e.lockAxis=e.lockAxis!==!1,l=new re(e),l.iOS=zt,zt&&!c()&&c(1),zt&&w.ticker.add(pt),Le=l._dc,T=w.to(l,{ease:"power4",paused:!0,inherit:!1,scrollX:r?"+=0.1":"+=0",scrollY:"+=0.1",modifiers:{scrollY:Xo(c,c(),function(){return T.pause()})},onUpdate:_t,onComplete:Le.vars.onComplete}),l};A.sort=function(a){if(Te(a))return O.sort(a);var e=W.pageYOffset||0;return A.getAll().forEach(function(o){return o._sortY=o.trigger?e+o.trigger.getBoundingClientRect().top:o.start+W.innerHeight}),O.sort(a||function(o,r){return(o.vars.refreshPriority||0)*-1e6+(o.vars.containerAnimation?1e6:o._sortY)-((r.vars.containerAnimation?1e6:r._sortY)+(r.vars.refreshPriority||0)*-1e6)})};A.observe=function(a){return new re(a)};A.normalizeScroll=function(a){if(typeof a>"u")return De;if(a===!0&&De)return De.enable();if(a===!1){De&&De.kill(),De=a;return}var e=a instanceof re?a:xi(a);return De&&De.target===e.target&&De.kill(),Kt(e.target)&&(De=e),e};A.core={_getVelocityProp:jn,_inputObserver:Fo,_scrollers:D,_proxies:mt,bridge:{ss:function(){rt||Zt("scrollStart"),rt=Me()},ref:function(){return Re}}};To()&&w.registerPlugin(A);function yi(){return t.jsxs("nav",{className:"nx-navbar","aria-label":"Navegación principal",children:[t.jsx("span",{className:"nx-navbar__logo",children:"NEXO"}),t.jsxs("ul",{className:"nx-navbar__links",children:[t.jsx("li",{children:t.jsx("a",{href:"#como-funciona",children:"Cómo funciona"})}),t.jsx("li",{children:t.jsx("a",{href:"#el-nodo",children:"El nodo"})}),t.jsx("li",{children:t.jsx("a",{href:"#roles",children:"Roles"})}),t.jsx("li",{children:t.jsx("a",{href:"#seguridad",children:"Seguridad"})}),t.jsx("li",{children:t.jsx("a",{href:"#contacto",children:"Contacto"})})]})]})}const bi="/assets/models/nodonuevo.glb";function vi({type:a,scale:e=1,showShield:o=!1,scrollProgress:r,isUserDragging:n,dragDeltaRef:i,dragSensitivity:s=.008,isMobile:l=!1}){const{gl:d}=Ho(),{scene:h}=mo(bi),m=f.useRef(),x=f.useRef(),g=f.useRef(),c=f.useRef(!1),y=f.useMemo(()=>{if(!h)return null;const k=h.clone(),M=d.capabilities.getMaxAnisotropy();return k.traverse(v=>{v.isMesh&&(v.castShadow=!0,v.receiveShadow=!0,v.material&&(Array.isArray(v.material)?v.material:[v.material]).forEach(X=>{X.map&&(X.map.anisotropy=M)}))}),k},[h,d]);return f.useEffect(()=>{if(!y||!m.current||!x.current||c.current)return;c.current=!0;const k=new $o().setFromObject(y),M=new on,v=new on;k.getSize(M),k.getCenter(v);const L=Math.max(M.x,M.y,M.z);if(L>0){const E=2.6*e/L;m.current.scale.setScalar(E),x.current.position.set(-v.x,-v.y,-v.z),x.current.rotation.set(0,0,0),setTimeout(()=>A.refresh(),100)}},[y,e]),Nn((k,M)=>{if(!x.current)return;let v=!1;if(n&&i?.current){const{dx:L,dy:X}=i.current;(Math.abs(L)>.001||Math.abs(X)>.001)&&(x.current.rotation.y+=L*s,x.current.rotation.x+=X*s,x.current.rotation.x=Math.max(-Math.PI/4,Math.min(Math.PI/4,x.current.rotation.x)),i.current={dx:0,dy:0}),v=!0}else x.current.rotation.y+=.24*M,v=!0;if(g.current?.material){const L=k.clock.getElapsedTime(),X=g.current.material;X.distort=Go.lerp(X.distort,.05,.08),g.current.position.y=.1+Math.sin(L*.3)*.004,g.current.rotation.y-=M*.04,v=!0}v&&k.invalidate()}),t.jsxs("group",{ref:m,position:[0,0,0],children:[t.jsx("group",{ref:x,children:y&&t.jsx("primitive",{object:y})}),o&&t.jsxs("mesh",{ref:g,scale:[1.15,1.15,1.15],position:[0,.1,0],children:[t.jsx("sphereGeometry",{args:[1.3,32,32]}),t.jsx(Uo,{attach:"material",color:"#2d6e30",distort:.05,speed:.4,roughness:.25,metalness:.9,transparent:!0,opacity:.45,wireframe:!0})]})]})}mo.preload("/assets/models/nodonuevo.glb");function yn(a){const e=document.createElement("canvas");e.width=256,e.height=256;const o=e.getContext("2d");o.imageSmoothingEnabled=!0,o.clearRect(0,0,256,256),o.strokeStyle="rgba(45, 110, 48, 0.45)",o.lineWidth=3,o.setLineDash([8,12]),o.beginPath(),o.arc(128,128,122,0,Math.PI*2),o.stroke(),o.setLineDash([]);const r=o.createRadialGradient(128,128,60,128,128,116);r.addColorStop(0,"#56b85a"),r.addColorStop(1,"#2d6e30"),o.fillStyle=r,o.beginPath(),o.arc(128,128,114,0,Math.PI*2),o.fill(),o.strokeStyle="rgba(255, 255, 255, 0.85)",o.lineWidth=5,o.beginPath(),o.arc(128,128,110,0,Math.PI*2),o.stroke(),o.fillStyle="#ffffff";const n=(s,l,d)=>{o.beginPath(),o.arc(s,l-18*d,22*d,0,Math.PI*2),o.fill(),o.beginPath(),o.arc(s,l+35*d,40*d,Math.PI,Math.PI*2),o.fill()};a==="single"?n(128,128,1.25):a==="group"?(n(98,138,.95),n(158,124,.95)):a==="group3"&&(n(85,142,.8),n(171,142,.8),n(128,116,.8));const i=new ho(e);return i.colorSpace=go,i.needsUpdate=!0,i}function wi(){const a=document.createElement("canvas");a.width=64,a.height=64;const e=a.getContext("2d");e.clearRect(0,0,64,64);const o=e.createRadialGradient(32,32,2,32,32,30);o.addColorStop(0,"#56b85a"),o.addColorStop(.3,"rgba(45, 110, 48, 0.8)"),o.addColorStop(1,"rgba(45, 110, 48, 0)"),e.fillStyle=o,e.beginPath(),e.arc(32,32,30,0,Math.PI*2),e.fill();const r=new ho(a);return r.colorSpace=go,r.needsUpdate=!0,r}const vr=[{pos:[-3,1.8,-.4],size:.52,type:"group"},{pos:[1.8,1.2,.5],size:.82,type:"single"},{pos:[-1.2,-.6,.3],size:.65,type:"group3"},{pos:[2.8,-1.6,-.2],size:.7,type:"group"},{pos:[-3.4,-1.4,-.1],size:.45,type:"single"},{pos:[-.2,2,.2],size:.55,type:"single"},{pos:[-2,.8,-.8],size:.16,type:"dot"},{pos:[.6,2.4,-.4],size:.18,type:"dot"},{pos:[-.6,.6,.8],size:.14,type:"dot"},{pos:[3.2,.4,-.6],size:.15,type:"dot"},{pos:[.1,-1.6,.3],size:.16,type:"dot"},{pos:[1.1,-.4,-.5],size:.15,type:"dot"},{pos:[-2.4,-2.4,.4],size:.13,type:"dot"},{pos:[3.8,-.8,.2],size:.14,type:"dot"},{pos:[-1.8,-1.8,-.6],size:.15,type:"dot"},{pos:[.2,-.2,-1.2],size:.13,type:"dot"}];function ki({count:a=80}){const e=f.useRef(),[o,r]=f.useMemo(()=>{const n=[],i=[];for(let s=0;s<a;s++)n.push((Math.random()-.5)*11,(Math.random()-.5)*7,(Math.random()-.5)*4),i.push((Math.random()-.5)*.05,(Math.random()-.5)*.05,(Math.random()-.5)*.05);return[new Float32Array(n),new Float32Array(i)]},[a]);return Nn((n,i)=>{if(!e.current)return;const s=e.current.geometry.attributes.position;for(let l=0;l<a;l++){const d=l*3;s.array[d]+=r[d]*i*4,s.array[d+1]+=r[d+1]*i*4,s.array[d+2]+=r[d+2]*i*4,Math.abs(s.array[d])>5.5&&(s.array[d]*=-.95),Math.abs(s.array[d+1])>3.5&&(s.array[d+1]*=-.95),Math.abs(s.array[d+2])>2&&(s.array[d+2]*=-.95)}s.needsUpdate=!0}),t.jsxs("points",{ref:e,children:[t.jsx("bufferGeometry",{children:t.jsx("bufferAttribute",{attach:"attributes-position",args:[o,3]})}),t.jsx("pointsMaterial",{color:"#56b85a",size:.06,transparent:!0,opacity:.65})]})}function ji({onHoverChange:a}){const e=f.useRef(),o=f.useMemo(()=>({single:yn("single"),group:yn("group"),group3:yn("group3"),dot:wi()}),[]),r=f.useMemo(()=>{const s=[];for(let l=0;l<vr.length;l++)for(let d=l+1;d<vr.length;d++){const h=vr[l].pos,m=vr[d].pos;Math.sqrt((h[0]-m[0])**2+(h[1]-m[1])**2+(h[2]-m[2])**2)<3.8&&(s.push(new on(...h)),s.push(new on(...m)))}return new Jo().setFromPoints(s)},[]);Nn(s=>{if(e.current){const l=s.clock.getElapsedTime();e.current.rotation.y=Math.sin(l*.15)*.2,e.current.rotation.x=Math.cos(l*.1)*.1,e.current.position.y=Math.sin(l*.3)*.08}});const n=s=>{s.stopPropagation(),a&&a(!0)},i=s=>{s.stopPropagation(),a&&a(!1)};return t.jsxs("group",{ref:e,onPointerOver:n,onPointerOut:i,children:[t.jsx(ki,{count:90}),t.jsx("lineSegments",{geometry:r,children:t.jsx("lineBasicMaterial",{color:"#2d6e30",transparent:!0,opacity:.25,linewidth:1})}),vr.map((s,l)=>{const d=o[s.type]||o.dot;return t.jsx("sprite",{position:s.pos,scale:[s.size*2,s.size*2,1],children:t.jsx("spriteMaterial",{map:d,transparent:!0})},l)})]})}function _i({onDrag:a,onDragStart:e,onDragEnd:o}){const r=f.useRef();return f.useEffect(()=>{const n=r.current;if(!n)return;let i=!1,s={x:0,y:0},l=null;const d=(v,L)=>{i=!0,s={x:v,y:L},n.style.cursor="grabbing",clearTimeout(l),e&&e()},h=(v,L)=>{if(!i)return;const X=v-s.x,E=L-s.y;s={x:v,y:L},a&&a(X,E)},m=()=>{i&&(i=!1,n.style.cursor="grab",l=setTimeout(()=>{o&&o()},600))},x=v=>d(v.clientX,v.clientY),g=v=>h(v.clientX,v.clientY),c=()=>m(),y=v=>{v.preventDefault(),d(v.touches[0].clientX,v.touches[0].clientY)},k=v=>{v.preventDefault(),i&&h(v.touches[0].clientX,v.touches[0].clientY)},M=()=>m();return n.addEventListener("mousedown",x),window.addEventListener("mousemove",g),window.addEventListener("mouseup",c),n.addEventListener("touchstart",y,{passive:!1}),window.addEventListener("touchmove",k,{passive:!1}),window.addEventListener("touchend",M,{passive:!0}),()=>{clearTimeout(l),n.removeEventListener("mousedown",x),window.removeEventListener("mousemove",g),window.removeEventListener("mouseup",c),n.removeEventListener("touchstart",y,{passive:!1}),window.removeEventListener("touchmove",k,{passive:!1}),window.removeEventListener("touchend",M,{passive:!0})}},[a,e,o]),t.jsx("div",{ref:r,style:{position:"absolute",inset:0,zIndex:10,cursor:"grab",touchAction:"none"}})}function Ln({type:a,scale:e=1,showShield:o=!1,coldLight:r=!1,interactive:n=!0,scrollProgress:i}){const[s,l]=f.useState(!1),[d,h]=f.useState(!1),m=f.useRef({dx:0,dy:0}),x=f.useRef(null);f.useEffect(()=>{const S=()=>{h(window.innerWidth<=768)};return S(),window.addEventListener("resize",S),()=>window.removeEventListener("resize",S)},[]);const g=f.useCallback((S,ye)=>{m.current={dx:S,dy:ye},l(!0)},[]),c=f.useCallback(()=>{l(!0)},[]),y=f.useCallback(()=>{l(!1),m.current={dx:0,dy:0}},[]),k="#ffffff",M=r?2.5:2.2,v="#c8e6c8",L=r?1.2:.8,X="#2d6e30",E=r?1.8:1.5,ke=e,G={position:"relative",width:"100%",height:"100%"};return t.jsxs("div",{style:G,children:[t.jsxs(Vo,{camera:{position:[0,0,a==="grid"?9:6],fov:45},gl:{antialias:!0,alpha:!0,powerPreference:"high-performance",precision:d?"mediump":"highp"},dpr:[1,Math.min(window.devicePixelRatio,2)],shadows:!d,frameloop:d?"always":"demand",style:{width:"100%",height:"100%",pointerEvents:"none"},children:[t.jsx("ambientLight",{intensity:d?.6:.3}),t.jsx("directionalLight",{position:[5,5,5],intensity:M,color:k,castShadow:!d}),t.jsx("directionalLight",{position:[-5,-2,3],intensity:L,color:v}),t.jsx("directionalLight",{position:[-3,5,-5],intensity:E,color:X}),!d&&t.jsx(Ko,{preset:"city"}),t.jsx(f.Suspense,{fallback:null,children:a==="grid"?t.jsx(ji,{}):t.jsx(vi,{type:a,scale:ke,showShield:o,scrollProgress:i,isUserDragging:s,dragDeltaRef:m,modelRef:x,dragSensitivity:d?.015:.008,isMobile:d})})]}),a!=="grid"&&t.jsx(_i,{onDrag:g,onDragStart:c,onDragEnd:y}),a!=="grid"&&d&&t.jsx("div",{"aria-hidden":"true",className:"nx-touch-rotate-hint",style:{position:"absolute",bottom:"0.85rem",left:"50%",transform:"translateX(-50%)",fontSize:"0.6rem",fontWeight:600,letterSpacing:"0.12em",textTransform:"uppercase",color:"rgba(74, 110, 76, 0.7)",whiteSpace:"nowrap",pointerEvents:"none",zIndex:20},children:"← Desliza para rotar →"})]})}const Ci=[{value:"",label:"Selecciona tu cargo"},{value:"rector",label:"Rector"},{value:"coordinador",label:"Coordinador"},{value:"docente",label:"Docente"},{value:"secretaria",label:"Secretaría de Educación"},{value:"otro",label:"Otro"}];function Si(a){const e={};a.nombre.trim()||(e.nombre="El nombre es obligatorio."),a.cargo||(e.cargo="Selecciona tu cargo."),a.institucion.trim()||(e.institucion="El nombre de la institución es obligatorio."),a.municipio.trim()||(e.municipio="El municipio y departamento son obligatorios.");const o=/^[^\s@]+@[^\s@]+\.[^\s@]+$/;a.email.trim()?o.test(a.email)||(e.email="Ingresa un correo electrónico válido."):e.email="El correo es obligatorio.";const r=a.whatsapp.replace(/\D/g,"");return a.whatsapp.trim()?r.length<10&&(e.whatsapp="El WhatsApp debe tener mínimo 10 dígitos."):e.whatsapp="El WhatsApp es obligatorio.",e}const Ei={nombre:"",cargo:"",institucion:"",municipio:"",email:"",whatsapp:"",mensaje:""};function qo({onClose:a}){const e=f.useRef(),o=f.useRef(),[r,n]=f.useState(Ei),[i,s]=f.useState({}),[l,d]=f.useState("idle");f.useEffect(()=>{const c=_.context(()=>{_.fromTo(e.current,{opacity:0},{opacity:1,duration:.25,ease:"power2.out"}),_.fromTo(o.current,{scale:.92,opacity:0,y:24},{scale:1,opacity:1,y:0,duration:.35,ease:"power3.out",delay:.05})});return()=>c.revert()},[]);const h=()=>{_.to(o.current,{scale:.94,opacity:0,y:16,duration:.22,ease:"power2.in"}),_.to(e.current,{opacity:0,duration:.28,ease:"power2.in",onComplete:a})};f.useEffect(()=>{const c=y=>{y.key==="Escape"&&h()};return window.addEventListener("keydown",c),()=>window.removeEventListener("keydown",c)},[]),f.useEffect(()=>(document.body.style.overflow="hidden",()=>{document.body.style.overflow=""}),[]);const m=c=>y=>n(k=>({...k,[c]:y.target.value})),x=async c=>{c.preventDefault();const y=Si(r);s(y),!(Object.keys(y).length>0)&&(d("loading"),console.log("[NEXO] Solicitud de contacto:",JSON.stringify(r,null,2)),await new Promise(k=>setTimeout(k,1200)),d("success"))},g=c=>({width:"100%",background:"var(--nx-void)",border:`1px solid ${i[c]?"rgba(255,80,80,0.6)":"var(--nx-border)"}`,borderRadius:"0.625rem",padding:"0.75rem 1rem",fontSize:"0.875rem",color:"var(--nx-white)",outline:"none",fontFamily:"inherit",transition:"border-color 0.2s",boxSizing:"border-box"});return t.jsxs("div",{ref:e,onClick:h,style:{position:"fixed",inset:0,zIndex:1e4,background:"rgba(0,0,0,0.75)",backdropFilter:"blur(12px)",WebkitBackdropFilter:"blur(12px)",display:"flex",alignItems:"center",justifyContent:"center",padding:"1.5rem"},"aria-modal":"true",role:"dialog","aria-label":"Formulario de contacto NEXO",children:[t.jsxs("div",{ref:o,className:"nx-modal-panel",onClick:c=>c.stopPropagation(),style:{background:"var(--nx-deep)",border:"1px solid var(--nx-border)",borderRadius:"1.25rem",width:"100%",maxWidth:"560px",maxHeight:"90vh",overflowY:"auto",padding:"2.5rem",position:"relative",willChange:"transform, opacity"},children:[t.jsx("button",{onClick:h,"aria-label":"Cerrar",style:{position:"absolute",top:"1.25rem",right:"1.25rem",background:"none",border:"1px solid var(--nx-border)",borderRadius:"50%",width:"32px",height:"32px",cursor:"pointer",display:"flex",alignItems:"center",justifyContent:"center",color:"var(--nx-muted)",transition:"border-color 0.2s, color 0.2s"},onMouseEnter:c=>{c.currentTarget.style.borderColor="var(--nx-text)",c.currentTarget.style.color="var(--nx-white)"},onMouseLeave:c=>{c.currentTarget.style.borderColor="var(--nx-border)",c.currentTarget.style.color="var(--nx-muted)"},children:t.jsx("svg",{width:"14",height:"14",viewBox:"0 0 14 14",fill:"none",stroke:"currentColor",strokeWidth:"1.5",strokeLinecap:"round",children:t.jsx("path",{d:"M2 2l10 10M12 2L2 12"})})}),l==="success"?t.jsxs("div",{style:{textAlign:"center",padding:"2rem 0"},children:[t.jsxs("svg",{width:"56",height:"56",viewBox:"0 0 56 56",fill:"none",style:{margin:"0 auto 1.5rem",display:"block",animation:"successPop 0.5s cubic-bezier(0.175,0.885,0.32,1.275)"},children:[t.jsx("circle",{cx:"28",cy:"28",r:"26",stroke:"var(--nx-green)",strokeWidth:"1.5"}),t.jsx("path",{d:"M18 28l7 7 14-14",stroke:"var(--nx-green)",strokeWidth:"2",strokeLinecap:"round",strokeLinejoin:"round"})]}),t.jsx("h3",{style:{fontSize:"1.1rem",fontWeight:800,color:"var(--nx-white)",marginBottom:"0.75rem"},children:"Tu solicitud fue recibida."}),t.jsx("p",{style:{fontSize:"0.875rem",color:"var(--nx-muted)",lineHeight:1.6},children:"Nos comunicaremos contigo en menos de 24 horas."})]}):t.jsxs(t.Fragment,{children:[t.jsxs("div",{style:{marginBottom:"2rem"},children:[t.jsx("div",{className:"nx-eyebrow",style:{marginBottom:"0.75rem"},children:"Contacto"}),t.jsx("h2",{style:{fontSize:"1.25rem",fontWeight:800,color:"var(--nx-white)",lineHeight:1.2},children:"Quiero que NEXO llegue a mi institución"})]}),t.jsxs("form",{onSubmit:x,noValidate:!0,style:{display:"flex",flexDirection:"column",gap:"1.1rem"},children:[t.jsxs("div",{children:[t.jsx("label",{style:{display:"block",fontSize:"0.78rem",fontWeight:600,color:"var(--nx-text)",marginBottom:"0.4rem"},children:"Nombre completo *"}),t.jsx("input",{type:"text",value:r.nombre,onChange:m("nombre"),placeholder:"Tu nombre completo",style:g("nombre"),onFocus:c=>c.target.style.borderColor="rgba(45, 110, 48, 0.5)",onBlur:c=>c.target.style.borderColor=i.nombre?"rgba(255,80,80,0.6)":"var(--nx-border)"}),i.nombre&&t.jsx("p",{style:{fontSize:"0.72rem",color:"#ff7070",marginTop:"0.3rem"},children:i.nombre})]}),t.jsxs("div",{children:[t.jsx("label",{style:{display:"block",fontSize:"0.78rem",fontWeight:600,color:"var(--nx-text)",marginBottom:"0.4rem"},children:"Cargo *"}),t.jsx("select",{value:r.cargo,onChange:m("cargo"),style:{...g("cargo"),appearance:"none",cursor:"pointer"},children:Ci.map(c=>t.jsx("option",{value:c.value,style:{background:"#f7fcf7"},children:c.label},c.value))}),i.cargo&&t.jsx("p",{style:{fontSize:"0.72rem",color:"#ff7070",marginTop:"0.3rem"},children:i.cargo})]}),t.jsxs("div",{children:[t.jsx("label",{style:{display:"block",fontSize:"0.78rem",fontWeight:600,color:"var(--nx-text)",marginBottom:"0.4rem"},children:"Nombre de la institución *"}),t.jsx("input",{type:"text",value:r.institucion,onChange:m("institucion"),placeholder:"I.E. San Carlos, Colegio...",style:g("institucion"),onFocus:c=>c.target.style.borderColor="rgba(45, 110, 48, 0.5)",onBlur:c=>c.target.style.borderColor=i.institucion?"rgba(255,80,80,0.6)":"var(--nx-border)"}),i.institucion&&t.jsx("p",{style:{fontSize:"0.72rem",color:"#ff7070",marginTop:"0.3rem"},children:i.institucion})]}),t.jsxs("div",{children:[t.jsx("label",{style:{display:"block",fontSize:"0.78rem",fontWeight:600,color:"var(--nx-text)",marginBottom:"0.4rem"},children:"Municipio y departamento *"}),t.jsx("input",{type:"text",value:r.municipio,onChange:m("municipio"),placeholder:"Medellín, Antioquia",style:g("municipio"),onFocus:c=>c.target.style.borderColor="rgba(45, 110, 48, 0.5)",onBlur:c=>c.target.style.borderColor=i.municipio?"rgba(255,80,80,0.6)":"var(--nx-border)"}),i.municipio&&t.jsx("p",{style:{fontSize:"0.72rem",color:"#ff7070",marginTop:"0.3rem"},children:i.municipio})]}),t.jsxs("div",{children:[t.jsx("label",{style:{display:"block",fontSize:"0.78rem",fontWeight:600,color:"var(--nx-text)",marginBottom:"0.4rem"},children:"Correo electrónico institucional *"}),t.jsx("input",{type:"email",value:r.email,onChange:m("email"),placeholder:"nombre@institución.edu.co",style:g("email"),onFocus:c=>c.target.style.borderColor="rgba(45, 110, 48, 0.5)",onBlur:c=>c.target.style.borderColor=i.email?"rgba(255,80,80,0.6)":"var(--nx-border)"}),i.email&&t.jsx("p",{style:{fontSize:"0.72rem",color:"#ff7070",marginTop:"0.3rem"},children:i.email})]}),t.jsxs("div",{children:[t.jsx("label",{style:{display:"block",fontSize:"0.78rem",fontWeight:600,color:"var(--nx-text)",marginBottom:"0.4rem"},children:"WhatsApp de contacto *"}),t.jsx("input",{type:"tel",value:r.whatsapp,onChange:m("whatsapp"),placeholder:"+57 310 000 0000",style:g("whatsapp"),onFocus:c=>c.target.style.borderColor="rgba(45, 110, 48, 0.5)",onBlur:c=>c.target.style.borderColor=i.whatsapp?"rgba(255,80,80,0.6)":"var(--nx-border)"}),i.whatsapp&&t.jsx("p",{style:{fontSize:"0.72rem",color:"#ff7070",marginTop:"0.3rem"},children:i.whatsapp})]}),t.jsxs("div",{children:[t.jsxs("label",{style:{display:"block",fontSize:"0.78rem",fontWeight:600,color:"var(--nx-text)",marginBottom:"0.4rem"},children:["¿Algo que quieras contarnos?",t.jsx("span",{style:{fontWeight:400,color:"var(--nx-muted)",marginLeft:"0.4rem"},children:"(opcional)"})]}),t.jsx("textarea",{value:r.mensaje,onChange:c=>{c.target.value.length<=300&&m("mensaje")(c)},placeholder:"Cuéntanos el contexto de tu institución...",rows:3,style:{...g("mensaje"),resize:"vertical",minHeight:"80px"},onFocus:c=>c.target.style.borderColor="rgba(45, 110, 48, 0.5)",onBlur:c=>c.target.style.borderColor="var(--nx-border)"}),t.jsxs("p",{style:{fontSize:"0.68rem",color:"var(--nx-muted-2)",marginTop:"0.25rem",textAlign:"right"},children:[r.mensaje.length,"/300"]})]}),t.jsx("button",{type:"submit",disabled:l==="loading",className:"nx-btn-primary",style:{width:"100%",padding:"0.9rem",fontSize:"0.9rem",marginTop:"0.5rem",opacity:l==="loading"?.75:1,cursor:l==="loading"?"not-allowed":"pointer",display:"flex",alignItems:"center",justifyContent:"center",gap:"0.6rem"},children:l==="loading"?t.jsxs(t.Fragment,{children:[t.jsxs("svg",{width:"16",height:"16",viewBox:"0 0 16 16",fill:"none",style:{animation:"spinnerRot 0.8s linear infinite"},children:[t.jsx("circle",{cx:"8",cy:"8",r:"6",stroke:"rgba(255,255,255,0.25)",strokeWidth:"2"}),t.jsx("path",{d:"M8 2a6 6 0 016 6",stroke:"white",strokeWidth:"2",strokeLinecap:"round"})]}),"Enviando..."]}):"Enviar solicitud"}),t.jsx("p",{style:{fontSize:"0.7rem",color:"var(--nx-muted-2)",textAlign:"center"},children:"Tus datos son tratados conforme a la Ley 1581 de protección de datos personales."})]})]})]}),t.jsx("style",{children:`
        @keyframes successPop {
          from { transform: scale(0.5); opacity: 0; }
          to   { transform: scale(1);   opacity: 1; }
        }
        @keyframes spinnerRot {
          from { transform: rotate(0deg); }
          to   { transform: rotate(360deg); }
        }
        /* Scroll del modal en móvil */
        @media (max-width: 560px) {
          [aria-label="Formulario de contacto NEXO"] > div {
            padding: 1.75rem 1.25rem !important;
            max-height: 95vh !important;
          }
        }

        @media (max-width: 768px) {
          .nx-modal-panel {
            position: fixed !important;
            bottom: 0 !important;
            left: 0 !important;
            right: 0 !important;
            top: auto !important;
            transform: none !important;
            max-width: 100% !important;
            width: 100% !important;
            border-radius: 1.25rem 1.25rem 0 0 !important;
            max-height: 92svh;
            overflow-y: auto;
            -webkit-overflow-scrolling: touch;
          }
        }
      `})]})}function Ct(a,e,{isFirst:o=!1,isLast:r=!1}={}){f.useEffect(()=>{const n=a.current,i=e.current;if(!n||!i)return;if(window.innerWidth<=768){if(_.set(i,{clearProps:"all"}),!o){_.set(i,{opacity:0,y:24});const d=new IntersectionObserver(([h])=>{h.isIntersecting&&(_.to(i,{opacity:1,y:0,duration:.55,ease:"power2.out"}),d.disconnect())},{threshold:.08});return d.observe(i),()=>d.disconnect()}_.set(i,{opacity:1,y:0});return}const l=_.context(()=>{o?_.set(i,{yPercent:0,opacity:1,scale:1}):_.fromTo(i,{yPercent:6,opacity:0,scale:.98},{yPercent:0,opacity:1,scale:1,ease:"none",scrollTrigger:{trigger:n,start:"top 90%",end:"top 20%",scrub:.8}}),r||_.fromTo(i,{yPercent:0,opacity:1,scale:1},{yPercent:-6,opacity:0,scale:.98,ease:"none",scrollTrigger:{trigger:n,start:"bottom 30%",end:"bottom top",scrub:.8}})});return()=>l.revert()},[a,e,o,r])}function Ri(){const a=f.useRef(),e=f.useRef(),o=f.useRef(),r=f.useRef(),n=f.useRef(),i=f.useRef(),s=f.useRef(),l=f.useRef(),d=f.useRef(),[h,m]=f.useState(!1),[x,g]=f.useState(!1);return f.useEffect(()=>{const c=()=>g(window.innerWidth<=768);return c(),window.addEventListener("resize",c),()=>window.removeEventListener("resize",c)},[]),Ct(a,e,{isFirst:!0}),f.useEffect(()=>{const c=window.innerWidth<=768;if(!a.current)return;const k=_.timeline({delay:c?.2:.1});return k.fromTo(o.current,{opacity:0,y:c?-8:-12},{opacity:1,y:0,duration:.6,ease:"power3.out"}).fromTo([r.current,n.current],{opacity:0,y:c?30:60},{opacity:1,y:0,duration:c?.7:1,ease:"expo.out",stagger:.12},"-=0.35").fromTo(i.current,{opacity:0,y:c?15:24},{opacity:1,y:0,duration:.75,ease:"power3.out"},"-=0.5").fromTo(s.current?.children||[],{opacity:0,y:12},{opacity:1,y:0,duration:.65,ease:"power3.out",stagger:.1},"-=0.4").fromTo(l.current,{opacity:0},{opacity:1,duration:.5,ease:"power2.out"},"-=0.2").fromTo(d.current,{opacity:0,scale:c?.98:.96},{opacity:1,scale:1,duration:c?1:1.5,ease:"expo.out"},.3),()=>k.kill()},[]),t.jsxs(t.Fragment,{children:[h&&t.jsx(qo,{onClose:()=>m(!1)}),t.jsx("div",{ref:a,className:"section-wrapper",id:"hero",children:t.jsxs("section",{ref:e,className:"section-inner",style:{background:"var(--nx-hero-gradient)",position:"relative",overflow:x?"visible":"hidden",paddingLeft:"var(--nx-section-px)",paddingRight:"var(--nx-section-px)",display:"flex",alignItems:"center"},children:[t.jsx("div",{"aria-hidden":"true",className:"nx-hero-glow-ambient",style:{position:"absolute",right:"5%",top:"50%",transform:"translateY(-50%)",width:"580px",height:"580px",background:"radial-gradient(circle, rgba(45, 110, 48, 0.12) 0%, transparent 65%)",borderRadius:"50%",pointerEvents:"none",zIndex:0}}),t.jsxs("div",{className:"nx-hero-grid",style:{position:"relative",zIndex:1,display:"grid",gridTemplateColumns:"1fr 1fr",gap:"3rem",alignItems:"center",maxWidth:"1280px",margin:"0 auto",width:"100%"},children:[t.jsxs("div",{className:"nx-hero-copy",children:[t.jsx("div",{ref:o,className:"nx-eyebrow",style:{opacity:0},children:"Sistema de Custodia Educativa en Tiempo Real — Colombia"}),t.jsxs("h1",{className:"nx-h1",style:{marginBottom:"1.75rem",overflow:"visible"},children:[t.jsx("span",{ref:r,style:{display:"block",opacity:0,shortcut:"none",willChange:"transform, opacity"},children:"La presencia estudiantil"}),t.jsx("span",{ref:n,style:{display:"block",opacity:0,shortcut:"none",willChange:"transform, opacity"},children:"ya no puede ser un punto ciego."})]}),t.jsx("p",{ref:i,className:"nx-body",style:{maxWidth:"500px",marginBottom:"2.5rem",opacity:0},children:"NEXO cierra ese vacío. Control de presencia, trazabilidad completa, comunicación institucional y procesos automatizados con análisis inteligente en tiempo real. En una infraestructura que opera con conexión autónoma y batería de respaldo ante cortes de luz."}),x&&t.jsx("div",{ref:d,className:"nx-hero-canvas nx-hero-canvas--inline","aria-label":"Modelo 3D del nodo NEXO",style:{height:"300px",borderRadius:"1rem",overflow:"hidden",position:"relative",marginBottom:"1rem",width:"100%"},children:t.jsx(Ln,{type:"solo",scale:1.15,coldLight:!0})}),t.jsxs("div",{ref:s,style:{display:"flex",alignItems:"center",gap:"1.25rem",flexWrap:"wrap",marginBottom:"1.25rem"},children:[t.jsx("button",{id:"hero-cta-primary",className:"nx-btn-primary",onClick:()=>m(!0),type:"button",style:{opacity:0},children:"Quiero que NEXO llegue a mi institución"}),t.jsxs("a",{href:"#como-funciona",className:"nx-link-arrow",id:"hero-cta-how",style:{opacity:0},children:["¿Eres rector o directivo? Ve cómo funciona",t.jsx("svg",{width:"14",height:"14",viewBox:"0 0 14 14",fill:"none","aria-hidden":!0,children:t.jsx("path",{d:"M2 7h10M8 3l4 4-4 4",stroke:"currentColor",strokeWidth:"1.5",strokeLinecap:"round",strokeLinejoin:"round"})})]})]}),t.jsx("p",{ref:l,className:"nx-micro",style:{opacity:0},children:"NEXO desea amparar la necesidad de corresponsabilidad familia-escuela, alerta temprana y trazabilidad de eventos en el sistema educativo colombiano."})]}),!x&&t.jsxs("div",{ref:d,className:"nx-hero-canvas","aria-label":"Modelo 3D del nodo NEXO",style:{height:"440px",borderRadius:"1.5rem",overflow:"hidden",position:"relative",opacity:0,willChange:"transform, opacity"},children:[t.jsx(Ln,{type:"solo",scale:1.1,coldLight:!0}),t.jsx("div",{"aria-hidden":"true",style:{position:"absolute",bottom:"1.25rem",left:"50%",transform:"translateX(-50%)",fontSize:"0.65rem",fontWeight:600,letterSpacing:"0.14em",textTransform:"uppercase",color:"var(--nx-muted-2)",whiteSpace:"nowrap"},children:"Nodo NEXO — Hardware biométrico"})]})]}),t.jsxs("div",{"aria-hidden":"true",className:"nx-hero-scroll-indicator",style:{position:"absolute",bottom:"2.5rem",left:"50%",transform:"translateX(-50%)",display:"flex",flexDirection:"column",alignItems:"center",gap:"0.5rem",opacity:0,animation:"heroScrollIn 0.6s ease 2s forwards"},children:[t.jsx("span",{className:"nx-micro",children:"Desliza"}),t.jsx("div",{style:{width:"1px",height:"48px",background:"linear-gradient(to bottom, rgba(107,127,163,0.6), transparent)",animation:"scrollBlink 2.2s ease-in-out infinite"}})]}),t.jsx("style",{children:`
            @keyframes heroScrollIn { to { opacity: 1; } }
            @keyframes scrollBlink {
              0%,100% { opacity: .2; }
              50%      { opacity: 1;  }
            }

            @media (max-width: 768px) {
              #hero .section-inner {
                justify-content: flex-start !important;
                padding-top: 5.5rem !important;
              }
              /* Mobile: single column, natural flow */
              #hero .nx-hero-grid {
                grid-template-columns: 1fr !important;
                gap: 0 !important;
                display: flex !important;
                flex-direction: column !important;
              }
              /* Copy first, canvas second — inner reorder via child flex order */
              #hero .nx-hero-copy {
                order: 0 !important;
                display: flex !important;
                flex-direction: column !important;
              }
              /* Canvas sits between subtitle and CTA */
              #hero .nx-hero-canvas {
                order: 1 !important;
                height: 300px !important;
                border-radius: 1rem !important;
                overflow: hidden !important;
                margin-bottom: 0.75rem !important;
              }
              /* Glow: full-width behind canvas on mobile */
              #hero .nx-hero-glow-ambient {
                width: 100% !important;
                height: 300px !important;
                right: 0 !important;
                top: 0 !important;
                transform: none !important;
                border-radius: 0 !important;
              }
              #hero .nx-link-arrow {
                display: none;
              }
              #hero .nx-hero-scroll-indicator {
                display: none !important;
              }
            }
          `})]})})]})}function fr(a,e={}){f.useEffect(()=>{if(!a.current)return;const o=a.current.querySelectorAll(".nx-reveal");if(!o.length)return;const r=new IntersectionObserver(n=>{n.forEach(i=>{i.isIntersecting&&i.target.classList.add("is-visible")})},{threshold:.15,rootMargin:"0px 0px -40px 0px",...e});return o.forEach(n=>r.observe(n)),()=>r.disconnect()},[])}const Mi=[{id:"lista",icon:t.jsxs("svg",{width:"28",height:"28",viewBox:"0 0 28 28",fill:"none",stroke:"currentColor",strokeWidth:"1.25",strokeLinecap:"round",strokeLinejoin:"round","aria-hidden":!0,children:[t.jsx("rect",{x:"4",y:"3",width:"20",height:"22",rx:"2"}),t.jsx("line",{x1:"9",y1:"9",x2:"19",y2:"9"}),t.jsx("line",{x1:"9",y1:"14",x2:"19",y2:"14"}),t.jsx("line",{x1:"9",y1:"19",x2:"15",y2:"19"})]}),title:"El registro manual de asistencia",body:"En la mayoría de las instituciones educativas colombianas, el registro de asistencia consume tiempo de clase que los docentes no pueden recuperar. Ese tiempo existe, se acumula día tras día, y es irrecuperable. No es tiempo administrativo: es tiempo de cátedra que los estudiantes no reciben."},{id:"salida",icon:t.jsxs("svg",{width:"28",height:"28",viewBox:"0 0 28 28",fill:"none",stroke:"currentColor",strokeWidth:"1.25",strokeLinecap:"round",strokeLinejoin:"round","aria-hidden":!0,children:[t.jsx("path",{d:"M18 14H4M4 14l4-4M4 14l4 4"}),t.jsx("path",{d:"M12 5h9a2 2 0 012 2v14a2 2 0 01-2 2h-9"})]}),title:"Los estudiantes que nadie ve salir",body:"Entre el cambio de una clase y la siguiente, entre una salida al baño y el regreso, hay intervalos donde las instituciones pierden trazabilidad sobre sus estudiantes. Cuando ocurre un incidente en ese margen invisible, la responsabilidad institucional queda expuesta sin capacidad de acción."},{id:"padre",icon:t.jsxs("svg",{width:"28",height:"28",viewBox:"0 0 28 28",fill:"none",stroke:"currentColor",strokeWidth:"1.25",strokeLinecap:"round",strokeLinejoin:"round","aria-hidden":!0,children:[t.jsx("path",{d:"M20 4H8a2 2 0 00-2 2v14a2 2 0 002 2h12a2 2 0 002-2V6a2 2 0 00-2-2z"}),t.jsx("line",{x1:"14",y1:"10",x2:"14",y2:"16"}),t.jsx("circle",{cx:"14",cy:"19",r:"0.5",fill:"currentColor"})]}),title:"Las familias fuera del circuito",body:"Las inasistencias registradas en papel o en sistemas desconectados llegan a los acudientes con retrasos de días — o no llegan. Las familias no forman parte del circuito de información en tiempo real, lo que genera brechas de comunicación que ninguna institución puede permitirse cuando está en cuestión el cuidado y la custodia de un menor."}];function Ti(){const a=f.useRef(),e=f.useRef(),o=f.useRef(),r=f.useRef([]),n=f.useRef();return fr(e),Ct(a,e),f.useEffect(()=>{const i=a.current;if(!i)return;if(window.innerWidth<=768){_.set([r.current,n.current],{opacity:1,y:0}),_.set(o.current?.querySelectorAll("span")||[],{opacity:1,y:0});return}const l=o.current;if(l){const h=l.textContent.trim().split(/\s+/);l.innerHTML=h.map(m=>`<span style="display:inline-block;opacity:0;transform:translateY(40px)">${m}</span>`).join("&nbsp;")}_.set(r.current,{opacity:0,y:50});const d=_.timeline({scrollTrigger:{trigger:i,start:"top 75%",toggleActions:"play none none none"}});return d.to(o.current?.querySelectorAll("span")||[],{opacity:1,y:0,duration:.6,stagger:.07,ease:"power3.out"},"-=0.25").to(r.current,{opacity:1,y:0,duration:.8,stagger:.15,ease:"power3.out"},"-=0.25").fromTo(n.current,{opacity:0,y:20},{opacity:1,y:0,duration:.8,ease:"power3.out"},"-=0.4"),()=>d.kill()},[]),t.jsxs("div",{ref:a,className:"section-wrapper",id:"el-problema",children:[t.jsx("section",{ref:e,className:"section-inner",style:{background:"linear-gradient(180deg, var(--nx-void) 0%, var(--nx-deep) 100%)",paddingLeft:"var(--nx-section-px)",paddingRight:"var(--nx-section-px)",display:"flex",alignItems:"center"},children:t.jsxs("div",{style:{maxWidth:"1280px",margin:"0 auto",width:"100%"},children:[t.jsx("h2",{ref:o,className:"nx-h2",style:{maxWidth:"700px",marginBottom:"5rem"},children:"Hay vacíos que el sistema educativo colombiano tiene pendiente cubrir — y que afectan a quienes más merecen protección."}),t.jsx("div",{className:"nx-problem-grid",style:{display:"grid",gridTemplateColumns:"repeat(3, 1fr)",gap:"2.5rem"},children:Mi.map(({id:i,icon:s,title:l,body:d},h)=>t.jsxs("div",{ref:m=>r.current[h]=m,className:"nx-problem-card nx-reveal",style:{borderTop:"1px solid var(--nx-border)",paddingTop:"2rem"},children:[t.jsx("div",{className:"nx-icon",style:{marginBottom:"1.5rem"},children:s}),t.jsx("h3",{className:"nx-h3",style:{marginBottom:"0.85rem"},children:l}),t.jsx("p",{className:"nx-body",style:{fontSize:"0.9rem"},children:d})]},i))}),t.jsx("div",{ref:n,className:"nx-reveal",style:{marginTop:"4.5rem",paddingTop:"2.5rem",borderTop:"1px solid var(--nx-border)",display:"flex",justifyContent:"center",opacity:0},children:t.jsx("p",{style:{maxWidth:"640px",textAlign:"center",fontSize:"1rem",lineHeight:1.75,color:"var(--nx-text)",fontStyle:"italic"},children:"NEXO no es una carga más. Es la infraestructura que cierra estos tres vacíos simultáneamente, en tiempo real, sin depender de la conexión a internet de las instituciones."})})]})}),t.jsx("style",{children:`
        @media (max-width: 768px) {
          #el-problema .nx-problem-grid {
            grid-template-columns: 1fr !important;
            gap: 1.25rem !important;
          }
          #el-problema .nx-problem-card {
            padding: 1.5rem !important;
            border-radius: var(--nx-radius-card) !important;
          }
        }
      `})]})}const so=[{num:"01",title:"El estudiante llega",body:"Coloca su huella en el nodo al entrar al salón. El registro ocurre al instante."},{num:"02",title:"El sistema protege la trazabilidad",body:"Si un estudiante no registró su ingreso al inicio de la jornada, el acudiente recibe una notificación automática vía WhatsApp. Si registró ingreso pero no aparece en una clase posterior, coordinación recibe una alerta inmediata para actuar antes de que la situación escale."},{num:"03",title:"La institución tiene visibilidad completa",body:"Coordinadores y rectores tienen a su disposición un panel en tiempo real con la información de la institución. Los profesores tienen al alcance de un botón su operación diaria: comunicación, registros, citaciones y más."},{num:"04",title:"Los patrones emergen solos",body:"Salidas frecuentes al baño, llegadas tarde recurrentes, evasiones entre clases — NEXO cruza la información y genera alertas antes de que el problema escale."}];function Li(){const a=f.useRef(),e=f.useRef(),o=f.useRef(),r=f.useRef([]),n=f.useRef(),i=f.useRef(),s=f.useRef(),l=f.useRef();return fr(e),Ct(a,e),f.useEffect(()=>{const d=a.current,h=o.current;if(!d||!h)return;if(window.innerWidth<=768){_.set([n.current,i.current,s.current,l.current],{opacity:1,y:0}),_.set(r.current,{opacity:1,scale:1}),r.current.forEach(g=>{if(!g)return;const c=g.querySelector(".nx-timeline__node");c&&(c.style.borderColor="var(--nx-green)",c.style.color="var(--nx-green)",c.style.backgroundColor="rgba(45, 110, 48, 0.08)")});return}_.set(r.current,{opacity:0,scale:.9});const x=_.context(()=>{_.fromTo([n.current,i.current,s.current],{opacity:0,y:30},{opacity:1,y:0,duration:.85,stagger:.15,ease:"power3.out",scrollTrigger:{trigger:d,start:"top 75%",toggleActions:"play none none none"}}),_.to(h,{strokeDashoffset:0,ease:"none",scrollTrigger:{trigger:d,start:"top top",end:"bottom bottom",scrub:1}});const g=[0,45,90,135];so.forEach((c,y)=>{A.create({trigger:d,start:`top -${g[y]}%`,toggleActions:"play none none none",onEnter:()=>{_.to(r.current[y],{opacity:1,scale:1,duration:.6,ease:"power3.out"}),_.to(r.current[y].querySelector(".nx-timeline__node"),{borderColor:"var(--nx-green)",color:"var(--nx-green)",backgroundColor:"rgba(45, 110, 48, 0.08)",boxShadow:"0 0 20px rgba(45, 110, 48, 0.3)",duration:.4})}})}),_.fromTo(l.current,{opacity:0,y:15},{opacity:1,y:0,duration:.6,ease:"power3.out",scrollTrigger:{trigger:d,start:"top -155%",toggleActions:"play none none none"}})});return()=>x.revert()},[]),t.jsxs("div",{ref:a,className:"section-wrapper section-wrapper--tall",id:"como-funciona",style:{height:"380vh"},children:[t.jsx("section",{ref:e,className:"section-inner",style:{background:"linear-gradient(180deg, var(--nx-deep) 0%, var(--nx-surface) 100%)",paddingLeft:"var(--nx-section-px)",paddingRight:"var(--nx-section-px)",display:"flex",alignItems:"center"},children:t.jsxs("div",{style:{maxWidth:"1280px",margin:"0 auto",width:"100%"},children:[t.jsx("div",{ref:n,className:"nx-eyebrow",style:{opacity:0},children:"El sistema"}),t.jsx("h2",{ref:i,className:"nx-h2",style:{maxWidth:"700px",marginBottom:"1rem",opacity:0},children:"Así opera NEXO."}),t.jsx("p",{ref:s,className:"nx-body",style:{maxWidth:"520px",marginBottom:"5rem",opacity:0},children:"Sin capacitaciones de semanas. Sin cambios de hábito forzados."}),t.jsxs("div",{className:"nx-timeline",style:{position:"relative"},children:[t.jsx("div",{style:{position:"absolute",top:"1.25rem",left:"calc(1.25rem + 20px)",right:"calc(1.25rem + 20px)",height:"2px",background:"var(--nx-border)"},"aria-hidden":"true"}),t.jsx("svg",{style:{position:"absolute",top:"1.25rem",left:"calc(1.25rem + 20px)",right:"calc(1.25rem + 20px)",width:"calc(100% - 2.5rem - 40px)",height:"2px",pointerEvents:"none",zIndex:1},"aria-hidden":"true",children:t.jsx("line",{ref:o,x1:"0",y1:"1",x2:"100%",y2:"1",stroke:"var(--nx-green)",strokeWidth:"2",strokeDasharray:"1200",strokeDashoffset:"1200",style:{filter:"drop-shadow(0 0 4px rgba(45, 110, 48, 0.6))"}})}),so.map(({num:d,title:h,body:m},x)=>t.jsxs("div",{ref:g=>r.current[x]=g,className:"nx-timeline__step",style:{position:"relative",zIndex:2},children:[t.jsx("div",{className:"nx-timeline__node",children:d}),t.jsxs("div",{style:{flex:1,display:"flex",flexDirection:"column"},children:[t.jsx("div",{className:"nx-timeline__title",children:h}),t.jsx("p",{className:"nx-timeline__body",children:m})]})]},d))]}),t.jsx("p",{ref:l,className:"nx-micro",style:{textAlign:"center",marginTop:"4rem",paddingTop:"2.5rem",borderTop:"1px solid var(--nx-border)",opacity:0},children:"Todo esto ocurre sin internet · Con batería de respaldo de 12 horas · Con conectividad M2M independiente"})]})}),t.jsx("style",{children:`
        @media (max-width: 768px) {
          #como-funciona .section-inner {
            min-height: auto !important;
            padding-bottom: 3rem !important;
          }
          #como-funciona h2 {
            margin-bottom: 2.5rem !important;
          }
          #como-funciona .nx-micro {
            text-align: left !important;
            padding-left: 0 !important;
          }
        }
      `})]})}const lo=[{before:"Lista de asistencia manual — tiempo de cátedra que no vuelve",after:"Registro automático al instante por estudiante"},{before:"Los acudientes se enteran de la inasistencia días después",after:"Notificación vía WhatsApp en tiempo real, el mismo momento"},{before:"Los coordinadores no saben quién salió ni cuántas veces",after:"Panel de alertas con patrones detectados automáticamente"},{before:"Los registros existen en papel, vulnerables y dispersos",after:"Registro automatizado digital disponible para su descarga en Word o Excel"},{before:"Si se va la luz o el internet, el sistema colapsa",after:"Operación autónoma: batería 12h + conectividad M2M propia"}];function Ni(){const a=f.useRef(),e=f.useRef(),o=f.useRef(),r=f.useRef(),n=f.useRef(),i=f.useRef(),s=f.useRef();return Ct(a,e),f.useEffect(()=>{const l=a.current,d=e.current;if(!l||!d)return;if(window.innerWidth<=768){_.set([i.current,s.current,r.current,n.current,o.current],{opacity:1,x:0,y:0});return}_.set(i.current,{opacity:0,x:-60}),_.set(s.current,{opacity:0,x:60}),_.set(d.querySelectorAll(".nx-ba-row"),{opacity:0,y:16});const m=_.timeline({scrollTrigger:{trigger:l,start:"top 75%",toggleActions:"play none none none"}});return m.fromTo(o.current,{opacity:0,y:-10},{opacity:1,y:0,duration:.55,ease:"power3.out"}).fromTo(r.current,{opacity:0,y:40},{opacity:1,y:0,duration:.9,ease:"expo.out"},"-=0.3").fromTo(n.current,{opacity:0,y:18},{opacity:1,y:0,duration:.75,ease:"power3.out"},"-=0.5").to(i.current,{opacity:1,x:0,duration:1,ease:"expo.out"},"-=0.25").to(s.current,{opacity:1,x:0,duration:1,ease:"expo.out"},"-=1.0").to(d.querySelectorAll(".nx-ba-row"),{opacity:1,y:0,duration:.55,ease:"power3.out",stagger:.08},"-=0.6"),()=>m.kill()},[]),t.jsxs("div",{ref:a,className:"section-wrapper",id:"propuesta-de-valor",children:[t.jsx("section",{ref:e,className:"section-inner",style:{background:"linear-gradient(180deg, var(--nx-deep) 0%, var(--nx-void) 100%)",paddingLeft:"var(--nx-section-px)",paddingRight:"var(--nx-section-px)",display:"flex",alignItems:"center"},children:t.jsxs("div",{style:{maxWidth:"1280px",margin:"0 auto",width:"100%"},children:[t.jsx("div",{ref:o,className:"nx-eyebrow",style:{marginBottom:"1rem",opacity:0},children:"Transformación"}),t.jsxs("h2",{ref:r,className:"nx-h2",style:{maxWidth:"720px",marginBottom:"1rem",opacity:0},children:["De la operación reactiva",t.jsx("br",{}),"a la custodia proactiva."]}),t.jsx("p",{ref:n,className:"nx-body",style:{maxWidth:"580px",marginBottom:"3.5rem",opacity:0},children:"Las instituciones que operan con NEXO no esperan que algo ocurra para actuar. Saben qué ocurre, cuándo ocurre y quién es responsable — antes de que escale."}),t.jsxs("div",{className:"nx-ba-table",children:[t.jsxs("div",{ref:i,className:"nx-ba-col nx-ba-col--before",style:{opacity:0},children:[t.jsx("div",{className:"nx-ba-header nx-ba-header--before",children:"Sin NEXO"}),lo.map(({before:l})=>t.jsxs("div",{className:"nx-ba-row",children:[t.jsx("span",{className:"nx-ba-dot nx-ba-dot--before"}),l]},l))]}),t.jsx("div",{className:"nx-ba-divider","aria-hidden":"true",children:t.jsx("svg",{width:"18",height:"18",viewBox:"0 0 18 18",fill:"none",children:t.jsx("path",{d:"M4 9h10M10 5l4 4-4 4",stroke:"var(--nx-blue)",strokeWidth:"1.5",strokeLinecap:"round",strokeLinejoin:"round"})})}),t.jsxs("div",{ref:s,className:"nx-ba-col nx-ba-col--after",style:{opacity:0},children:[t.jsx("div",{className:"nx-ba-header nx-ba-header--after",children:"Con NEXO"}),lo.map(({after:l})=>t.jsxs("div",{className:"nx-ba-row nx-ba-row--after",children:[t.jsx("span",{className:"nx-ba-dot nx-ba-dot--after"}),l]},l))]})]})]})}),t.jsx("style",{children:`
        @media (max-width: 768px) {
          #propuesta-de-valor .nx-ba-col {
            border-radius: var(--nx-radius-card) !important;
            padding: 1.25rem !important;
          }
          #propuesta-de-valor .nx-ba-col--before {
            margin-bottom: 1rem;
          }
          #propuesta-de-valor [style*="marginTop: '2.5rem'"] {
            margin-top: 1.5rem !important;
          }
        }
      `})]})}const co=[{id:"steel",label:"Acero inoxidable",meaning:"Resiste el uso intensivo diario de cientos de estudiantes sin degradarse.",hotspotPos:{top:"20%",left:"28%"}},{id:"battery",label:"Batería 12 horas",meaning:"Opera durante cortes de luz sin interrupciones. Sin excusas.",hotspotPos:{top:"45%",left:"14%"}},{id:"sim",label:"Conectividad M2M",meaning:"Tiene su propia SIM Card. No depende del WiFi de la institución.",hotspotPos:{top:"68%",left:"26%"}},{id:"encrypt",label:"Encriptado de extremo a extremo",meaning:"Los datos biométricos viajan y se almacenan con encriptación completa en cada capa del sistema.",hotspotPos:{top:"30%",right:"18%"}}];function zi({spec:a,isActive:e,onClick:o,isMobile:r}){const n=i=>{i.stopPropagation(),o(a.id)};return t.jsxs("div",{className:"nx-hotspot",style:{position:"absolute",...a.hotspotPos,zIndex:10},onPointerUp:n,onKeyDown:i=>i.key==="Enter"&&o(a.id),role:"button",tabIndex:0,"aria-label":`Ver detalle: ${a.label}`,"aria-pressed":e,children:[t.jsx("div",{className:"nx-hotspot__ring"}),t.jsx("div",{className:"nx-hotspot__dot",style:{width:r?"18px":"12px",height:r?"18px":"12px",transform:e?"scale(1.5)":"scale(1)",boxShadow:e?"0 0 0 5px rgba(45, 110, 48, 0.25)":"none",transition:"transform 0.2s var(--nx-ease), box-shadow 0.2s"}})]})}function Pi({active:a,specs:e,onClose:o}){const r=e.find(n=>n.id===a);return t.jsx("div",{className:"nx-node-panel nx-reveal nx-reveal-delay-4",style:{display:"flex",flexDirection:"column",justifyContent:"center",minHeight:"200px"},children:r?t.jsxs("div",{style:{animation:"panelIn 0.2s var(--nx-ease)"},children:[t.jsx("div",{style:{fontSize:"0.65rem",fontWeight:700,color:"var(--nx-green)",textTransform:"uppercase",letterSpacing:"0.12em",marginBottom:"1rem"},children:"Especificación"}),t.jsx("h3",{style:{fontSize:"1.25rem",fontWeight:800,color:"var(--nx-white)",lineHeight:1.2,marginBottom:"1rem"},children:r.label}),t.jsx("p",{style:{fontSize:"0.9rem",color:"var(--nx-muted)",lineHeight:1.65},children:r.meaning}),t.jsx("button",{type:"button",onPointerUp:o,style:{marginTop:"1.5rem",background:"none",border:"1px solid var(--nx-border)",borderRadius:"100px",padding:"0.4rem 1rem",fontSize:"0.75rem",color:"var(--nx-muted)",cursor:"pointer",fontFamily:"var(--nx-font)",transition:"border-color 0.2s, color 0.2s",alignSelf:"flex-start"},"aria-label":"Cerrar panel",children:"Cerrar ×"})]},a):t.jsxs("div",{style:{opacity:.45},children:[t.jsx("div",{style:{width:"40px",height:"1px",background:"var(--nx-border)",marginBottom:"1.25rem"}}),t.jsx("p",{style:{fontSize:"0.8rem",color:"var(--nx-muted)",lineHeight:1.65},children:"Toca un punto parpadeante para explorar las especificaciones del nodo."})]})})}function Ai(){const a=f.useRef(),e=f.useRef(),[o,r]=f.useState(null),[n,i]=f.useState(1.12),[s,l]=f.useState(!1);fr(e),f.useEffect(()=>{const h=()=>l(window.innerWidth<=768);return h(),window.addEventListener("resize",h),()=>window.removeEventListener("resize",h)},[]),Ct(a,e),f.useEffect(()=>{const h=a.current,m=e.current;if(!h||!m)return;if(window.innerWidth<=768){i(1);return}const g=m.querySelectorAll(".nx-hotspot");_.set(g,{opacity:0,scale:0});const c=_.timeline({scrollTrigger:{trigger:h,start:"top 75%",toggleActions:"play none none none"}}),y={val:1.12};return c.to(y,{val:1,duration:1.4,ease:"power2.out",onUpdate:()=>{i(y.val)}}),c.to(g,{opacity:1,scale:1,duration:.5,stagger:.18,ease:"back.out(1.7)"},"-=0.1"),()=>c.kill()},[]);const d=h=>r(m=>m===h?null:h);return t.jsxs("div",{ref:a,className:"section-wrapper section-wrapper--tall",id:"el-nodo",children:[t.jsx("section",{ref:e,className:"section-inner",style:{background:"linear-gradient(180deg, var(--nx-surface) 0%, var(--nx-deep) 100%)",overflow:s?"visible":"hidden",paddingLeft:"var(--nx-section-px)",paddingRight:"var(--nx-section-px)",display:"flex",alignItems:"center"},children:t.jsxs("div",{style:{maxWidth:"1280px",margin:"0 auto",width:"100%"},children:[t.jsx("div",{className:"nx-eyebrow nx-reveal",children:"El hardware"}),t.jsx("h2",{className:"nx-h2 nx-reveal nx-reveal-delay-1",style:{maxWidth:"680px",marginBottom:"1rem"},children:"Construido para durar en las condiciones reales de una institución educativa colombiana."}),t.jsx("p",{className:"nx-body nx-reveal nx-reveal-delay-2",style:{maxWidth:"520px",marginBottom:"4rem"},children:"No diseñado en un laboratorio ideal. Diseñado para cortes de luz, para humedad, para el uso diario de cientos de estudiantes — y para seguir funcionando."}),t.jsxs("div",{className:"nx-node-grid",style:{display:"grid",gridTemplateColumns:"1fr 1fr",gap:"4rem",alignItems:"center"},children:[t.jsxs("div",{className:"nx-node-canvas-wrap nx-reveal nx-reveal-delay-3",style:{position:"relative",height:"520px"},children:[t.jsx("div",{style:{width:"100%",height:"100%",borderRadius:s?"0":"1.25rem",overflow:s?"visible":"hidden"},children:t.jsx(Ln,{type:"solo",scale:n,coldLight:!0,interactive:!0})}),co.map(h=>t.jsx(zi,{spec:h,isActive:o===h.id,onClick:d,isMobile:s},h.id)),t.jsx("div",{"aria-hidden":"true",className:"nx-cursor-hint-desktop",style:{position:"absolute",bottom:"1rem",left:"50%",transform:"translateX(-50%)",fontSize:"0.65rem",color:"var(--nx-muted-2)",letterSpacing:"0.1em",textTransform:"uppercase",whiteSpace:"nowrap",pointerEvents:"none"},children:"Rota con el cursor · Toca los puntos"})]}),t.jsx(Pi,{active:o,specs:co,onClose:()=>r(null)})]})]})}),t.jsx("style",{children:`
        @keyframes panelIn {
          from { opacity: 0; transform: translateY(8px); }
          to   { opacity: 1; transform: translateY(0); }
        }

        @media (max-width: 768px) {
          #el-nodo .nx-node-grid {
            grid-template-columns: 1fr !important;
            gap: 1.5rem !important;
          }
          #el-nodo .nx-node-canvas-wrap {
            height: 360px !important;
            border-radius: 1rem !important;
            overflow: visible !important;
            margin-bottom: 0 !important;
          }
          #el-nodo .nx-hotspot {
            display: block !important;
            width: 28px !important;
            height: 28px !important;
            touch-action: manipulation;
          }
          #el-nodo .nx-hotspot__ring {
            width: 18px !important;
            height: 18px !important;
          }
          #el-nodo .nx-cursor-hint-desktop { display: none !important; }
          #el-nodo .nx-node-panel {
            padding: 1.25rem !important;
            background: var(--nx-surface) !important;
            border: 1px solid var(--nx-border) !important;
            border-radius: 1rem !important;
            min-height: auto !important;
          }
        }
      `})]})}const uo=[{id:"rector",label:"Rector",icon:t.jsxs("svg",{width:"24",height:"24",viewBox:"0 0 24 24",fill:"none",stroke:"currentColor",strokeWidth:"1.25",strokeLinecap:"round",strokeLinejoin:"round",children:[t.jsx("circle",{cx:"12",cy:"8",r:"4"}),t.jsx("path",{d:"M4 20c0-4 3.6-7 8-7s8 3 8 7"}),t.jsx("path",{d:"M17 4l2 2-2 2"})]}),headline:"La firma institucional queda protegida.",body:"Los rectores tienen acceso centralizado a la información de su institución — lo que ocurre, cuándo ocurre y qué acciones se tomaron. Todo disponible para accionar con respaldo real.",features:["Auditoría completa con marca de tiempo por acción","Informes descargables listos para entes de control"]},{id:"coordinador",label:"Coordinador",icon:t.jsxs("svg",{width:"24",height:"24",viewBox:"0 0 24 24",fill:"none",stroke:"currentColor",strokeWidth:"1.25",strokeLinecap:"round",strokeLinejoin:"round",children:[t.jsx("rect",{x:"2",y:"3",width:"20",height:"14",rx:"2"}),t.jsx("line",{x1:"8",y1:"21",x2:"16",y2:"21"}),t.jsx("line",{x1:"12",y1:"17",x2:"12",y2:"21"}),t.jsx("line",{x1:"6",y1:"8",x2:"18",y2:"8"}),t.jsx("line",{x1:"6",y1:"12",x2:"13",y2:"12"})]}),headline:"Los problemas se detectan antes de escalar.",body:"Los coordinadores ven evasiones entre clases, salidas frecuentes y llegadas tarde recurrentes — todo en un panel en tiempo real, con alertas automáticas configurables por umbral antes de que cualquier situación se convierta en incidente.",features:["Panel de patrones y anomalías en tiempo real","Alertas automáticas configurables por umbral","Historial completo por estudiante a disposición del coordinador"]},{id:"profesor",label:"Profesor",icon:t.jsxs("svg",{width:"24",height:"24",viewBox:"0 0 24 24",fill:"none",stroke:"currentColor",strokeWidth:"1.25",strokeLinecap:"round",strokeLinejoin:"round",children:[t.jsx("path",{d:"M12 2L2 7l10 5 10-5-10-5z"}),t.jsx("path",{d:"M2 17l10 5 10-5"}),t.jsx("path",{d:"M2 12l10 5 10-5"})]}),headline:"La carga administrativa de los docentes se reduce a gran escala, permitiendo orientar ese tiempo al desarrollo pedagógico.",body:"El registro de asistencia ocurre automáticamente. Los docentes pueden citar acudientes con un botón, reportar daños o incidentes desde su teléfono, y dedicar el tiempo de clase exclusivamente a enseñar.",features:["Asistencia automática — sin intervención manual","Citar acudientes desde el móvil en un toque","Reportes de incidentes y daños desde la app"]}];function Oi(){const a=f.useRef(),e=f.useRef(),o=f.useRef(),r=f.useRef(),[n,i]=f.useState(0),s=f.useRef(0),l=f.useRef(!1);fr(e),Ct(a,e),f.useEffect(()=>{const m=window.innerWidth<=768,x=o.current?.querySelectorAll(".nx-tab");if(m){x&&_.set(x,{opacity:1,y:0});return}x&&_.set(x,{opacity:0,y:15});const g=A.create({trigger:a.current,start:"top 75%",toggleActions:"play none none none",onEnter:()=>{x&&_.to(x,{opacity:1,y:0,duration:.6,stagger:.08,ease:"power3.out"})}});return()=>g.kill()},[]);const d=f.useCallback(m=>{m===s.current||l.current||(l.current=!0,_.to(r.current,{opacity:0,y:-12,duration:.18,ease:"power2.in",onComplete:()=>{i(m),s.current=m}}))},[]);f.useEffect(()=>{r.current&&_.fromTo(r.current,{opacity:0,y:12},{opacity:1,y:0,duration:.28,ease:"power3.out",onComplete:()=>{l.current=!1}})},[n]);const h=uo[n];return t.jsxs("div",{ref:a,className:"section-wrapper",id:"roles",children:[t.jsx("section",{ref:e,className:"section-inner",style:{background:"linear-gradient(180deg, var(--nx-void) 0%, var(--nx-deep) 100%)",paddingLeft:"var(--nx-section-px)",paddingRight:"var(--nx-section-px)",display:"flex",alignItems:"center"},children:t.jsxs("div",{style:{maxWidth:"1280px",margin:"0 auto",width:"100%"},children:[t.jsx("div",{className:"nx-eyebrow nx-reveal",children:"Por rol"}),t.jsx("h2",{className:"nx-h2 nx-reveal nx-reveal-delay-1",style:{maxWidth:"700px",marginBottom:"1rem"},children:"NEXO opera diferente para cada rol."}),t.jsx("p",{className:"nx-body nx-reveal nx-reveal-delay-2",style:{maxWidth:"500px",marginBottom:"3.5rem"},children:"Pero todos ven lo mismo: control total."}),t.jsx("div",{ref:o,className:"nx-tabs",role:"tablist","aria-label":"Roles de usuario",children:uo.map((m,x)=>t.jsx("button",{id:`tab-${m.id}`,className:`nx-tab${n===x?" active":""}`,onClick:()=>d(x),"aria-selected":n===x,"aria-controls":`panel-${m.id}`,role:"tab",type:"button",children:m.label},m.id))}),t.jsxs("div",{ref:r,className:"nx-role-panel-grid",id:`panel-${h.id}`,role:"tabpanel","aria-labelledby":`tab-${h.id}`,style:{display:"grid",gridTemplateColumns:"auto 1fr",gap:"3.5rem",alignItems:"start",opacity:1,willChange:"opacity, transform"},children:[t.jsx("div",{className:"nx-icon",style:{width:"3.5rem",height:"3.5rem",borderRadius:"1rem",marginTop:"0.25rem"},children:h.icon}),t.jsxs("div",{children:[t.jsx("div",{style:{fontSize:"0.7rem",fontWeight:700,letterSpacing:"0.12em",textTransform:"uppercase",color:"var(--nx-blue)",marginBottom:"0.75rem"},children:h.label}),t.jsx("h3",{style:{fontSize:"1.4rem",fontWeight:800,color:"var(--nx-white)",marginBottom:"0.85rem",lineHeight:1.2},children:h.headline}),t.jsx("p",{className:"nx-body",style:{marginBottom:"2rem",maxWidth:"560px"},children:h.body}),t.jsx("ul",{className:"nx-role-features",style:{display:"flex",flexDirection:"column",gap:"0.75rem",listStyle:"none",padding:0},children:h.features.map(m=>t.jsxs("li",{style:{display:"flex",alignItems:"center",gap:"0.85rem",fontSize:"0.875rem",color:"var(--nx-text)"},children:[t.jsxs("svg",{width:"16",height:"16",viewBox:"0 0 16 16",fill:"none","aria-hidden":!0,children:[t.jsx("circle",{cx:"8",cy:"8",r:"7",stroke:"var(--nx-blue)",strokeWidth:"1"}),t.jsx("path",{d:"M5 8l2 2 4-4",stroke:"var(--nx-blue)",strokeWidth:"1.25",strokeLinecap:"round",strokeLinejoin:"round"})]}),m]},m))})]})]})]})}),t.jsx("style",{children:`
        @media (max-width: 768px) {
          .nx-role-panel-grid {
            grid-template-columns: 1fr !important;
            gap: 1.25rem !important;
          }
          .nx-role-panel-grid > div:first-child {
            display: flex;
            align-items: center;
            gap: 0.875rem;
          }
          .nx-role-features { margin-top: 1rem !important; }
          .nx-role-features li { font-size: 0.85rem !important; }
        }
      `})]})}const Wi=f.forwardRef(function({href:e,filename:o="nexo.apk",children:r,className:n="",id:i,...s},l){const d=f.useRef();f.useImperativeHandle(l,()=>d.current);const h=m=>{m.preventDefault(),_.timeline().to(d.current,{scale:.92,duration:.1,ease:"power2.in"}).to(d.current,{scale:1.05,duration:.2,ease:"elastic.out(1, 0.3)"}).to(d.current,{scale:1,duration:.15,ease:"power2.out"}),window.open(e,"_blank","noopener,noreferrer")};return t.jsx("button",{ref:d,onClick:h,className:n,id:i,style:{...s.style},...s,children:r})}),Di=[{id:"android",name:"Android",glowColor:"rgba(61,220,132,0.35)",href:"https://nexo-bay-mu.vercel.app/app/instalar/android",icon:t.jsxs("svg",{width:"56",height:"56",viewBox:"0 0 56 56",fill:"none",stroke:"currentColor",strokeWidth:"1.1",strokeLinecap:"round",strokeLinejoin:"round",children:[t.jsx("path",{d:"M14 20h28v24a4 4 0 01-4 4H18a4 4 0 01-4-4V20z"}),t.jsx("path",{d:"M20 20V14a8 8 0 0116 0v6"}),t.jsx("circle",{cx:"21",cy:"33",r:"2",fill:"currentColor",stroke:"none"}),t.jsx("circle",{cx:"35",cy:"33",r:"2",fill:"currentColor",stroke:"none"}),t.jsx("line",{x1:"10",y1:"26",x2:"10",y2:"36"}),t.jsx("line",{x1:"46",y1:"26",x2:"46",y2:"36"})]})},{id:"ios",name:"iOS",glowColor:"rgba(180,180,185,0.35)",href:"https://nexo-bay-mu.vercel.app/app/instalar/ios",icon:t.jsxs("svg",{width:"56",height:"56",viewBox:"0 0 56 56",fill:"none",stroke:"currentColor",strokeWidth:"1.1",strokeLinecap:"round",strokeLinejoin:"round",children:[t.jsx("path",{d:"M37 4C34 4 31 6 28 6s-6-2-9-2C12 4 6 10 6 19c0 13 8 31 14 31 3 0 4-2 8-2s5 2 8 2c6 0 14-18 14-29C50 10 44 4 37 4z"}),t.jsx("path",{d:"M28 6V2"})]})},{id:"windows",name:"Windows",glowColor:"rgba(0,120,212,0.35)",href:"https://nexo-bay-mu.vercel.app/app/instalar/windows",icon:t.jsxs("svg",{width:"56",height:"56",viewBox:"0 0 56 56",fill:"none",stroke:"currentColor",strokeWidth:"1.1",strokeLinecap:"round",strokeLinejoin:"round",children:[t.jsx("rect",{x:"4",y:"4",width:"22",height:"22",rx:"2"}),t.jsx("rect",{x:"30",y:"4",width:"22",height:"22",rx:"2"}),t.jsx("rect",{x:"4",y:"30",width:"22",height:"22",rx:"2"}),t.jsx("rect",{x:"30",y:"30",width:"22",height:"22",rx:"2"})]})},{id:"mac",name:"Mac",glowColor:"rgba(180,180,185,0.35)",href:"https://nexo-bay-mu.vercel.app/app/instalar/mac",icon:t.jsxs("svg",{width:"56",height:"56",viewBox:"0 0 56 56",fill:"none",stroke:"currentColor",strokeWidth:"1.1",strokeLinecap:"round",strokeLinejoin:"round",children:[t.jsx("rect",{x:"6",y:"6",width:"44",height:"32",rx:"4"}),t.jsx("line",{x1:"2",y1:"48",x2:"54",y2:"48"}),t.jsx("line",{x1:"20",y1:"38",x2:"36",y2:"38"}),t.jsx("line",{x1:"28",y1:"38",x2:"28",y2:"48"})]})},{id:"linux",name:"Linux",glowColor:"rgba(255,185,0,0.30)",href:"https://nexo-bay-mu.vercel.app/app/instalar/linux",icon:t.jsxs("svg",{width:"56",height:"56",viewBox:"0 0 56 56",fill:"none",stroke:"currentColor",strokeWidth:"1.1",strokeLinecap:"round",strokeLinejoin:"round",children:[t.jsx("path",{d:"M28 4c-11 0-16 8-16 18v4c0 3-1 6-3 9C7 38 6 40 6 42c0 3 4 6 11 6 3 0 6-1 8-3 1 1 2 1 3 1s2 0 3-1c2 2 5 3 8 3 7 0 11-3 11-6 0-2-1-4-3-7-2-3-3-6-3-9v-4C44 12 39 4 28 4z"}),t.jsx("circle",{cx:"21",cy:"24",r:"2",fill:"currentColor",stroke:"none"}),t.jsx("circle",{cx:"35",cy:"24",r:"2",fill:"currentColor",stroke:"none"}),t.jsx("path",{d:"M22 34c1.5 2 4 3 6 3s4.5-1 6-3"})]})}];function Bi({id:a,name:e,icon:o,href:r,glowColor:n}){const i=f.useRef(),s=f.useRef(),l=f.useRef();f.useEffect(()=>{const m=i.current,x=s.current,g=l.current;if(!m)return;const c=M=>{const v=m.getBoundingClientRect(),L=v.left+v.width/2,X=v.top+v.height/2,E=(M.clientX-L)/(v.width/2),ke=(M.clientY-X)/(v.height/2);_.to(g,{rotateX:-ke*8,rotateY:E*8,duration:.25,ease:"power2.out"})},y=()=>{_.to(m,{scale:1.12,y:-6,duration:.3,ease:"power2.out"}),_.to(x,{opacity:1,duration:.3,ease:"power2.out"})},k=()=>{_.to(m,{scale:1,y:0,duration:.25,ease:"power2.inOut"}),_.to(g,{rotateX:0,rotateY:0,duration:.35,ease:"power2.inOut"}),_.to(x,{opacity:0,duration:.25,ease:"power2.inOut"})};if(!window.matchMedia("(pointer: coarse)").matches)return m.addEventListener("mouseenter",y),m.addEventListener("mouseleave",k),m.addEventListener("mousemove",c),()=>{m.removeEventListener("mouseenter",y),m.removeEventListener("mouseleave",k),m.removeEventListener("mousemove",c)}},[]);const d={display:"flex",flexDirection:"column",alignItems:"center",gap:"1rem",padding:"2.25rem 2rem",background:"var(--nx-surface)",border:"1px solid var(--nx-border)",borderRadius:"1.25rem",cursor:"pointer",position:"relative",overflow:"hidden",willChange:"transform",transformStyle:"preserve-3d",textDecoration:"none",transition:"border-color 0.3s"},h=t.jsxs(t.Fragment,{children:[t.jsx("div",{ref:s,"aria-hidden":"true",style:{position:"absolute",inset:0,background:`radial-gradient(circle at center, ${n} 0%, transparent 70%)`,opacity:0,pointerEvents:"none",borderRadius:"1.25rem"}}),t.jsx("div",{ref:l,style:{color:"var(--nx-green)",position:"relative",zIndex:1,transformStyle:"preserve-3d"},children:o}),t.jsx("span",{style:{fontSize:"0.8rem",fontWeight:600,color:"var(--nx-text)",letterSpacing:"0.04em",position:"relative",zIndex:1},children:e})]});return a==="android"?t.jsx(Wi,{ref:i,href:r,id:`download-btn-${a}`,"aria-label":`Descargar NEXO para ${e}`,style:d,onMouseEnter:m=>m.currentTarget.style.borderColor="rgba(45, 110, 48, 0.4)",onMouseLeave:m=>m.currentTarget.style.borderColor="var(--nx-border)",children:h}):t.jsx("a",{ref:i,href:r,target:"_blank",rel:"noopener noreferrer",id:`download-btn-${a}`,"aria-label":`Descargar NEXO para ${e}`,style:d,onMouseEnter:m=>m.currentTarget.style.borderColor="rgba(45, 110, 48, 0.4)",onMouseLeave:m=>m.currentTarget.style.borderColor="var(--nx-border)",children:h})}function Ii(){const a=f.useRef(),e=f.useRef();return fr(e),Ct(a,e),t.jsxs("div",{ref:a,className:"section-wrapper",id:"descarga",children:[t.jsx("section",{ref:e,className:"section-inner",style:{background:"linear-gradient(180deg, var(--nx-void) 0%, var(--nx-surface) 50%, var(--nx-deep) 100%)",paddingLeft:"var(--nx-section-px)",paddingRight:"var(--nx-section-px)",display:"flex",alignItems:"center"},children:t.jsxs("div",{style:{maxWidth:"1280px",margin:"0 auto",width:"100%"},children:[t.jsxs("div",{style:{textAlign:"center",marginBottom:"5rem"},children:[t.jsx("div",{className:"nx-eyebrow nx-reveal",style:{justifyContent:"center",display:"flex"},children:"La aplicación"}),t.jsx("h2",{className:"nx-h2 nx-reveal nx-reveal-delay-1",style:{marginBottom:"1rem"},children:"Tu panel de control institucional."}),t.jsx("p",{className:"nx-body nx-reveal nx-reveal-delay-2",style:{maxWidth:"480px",margin:"0 auto"},children:"Disponible para Android, iOS, Windows, Mac y Linux. La misma información, en tiempo real, donde estés."})]}),t.jsx("div",{className:"nx-platform-grid nx-reveal nx-reveal-delay-3",style:{display:"flex",gap:"1.25rem",justifyContent:"center",flexWrap:"wrap"},children:Di.map(o=>t.jsx(Bi,{...o},o.id))})]})}),t.jsx("style",{children:`
        @media (max-width: 768px) {
          .nx-platform-grid {
            display: grid !important;
            grid-template-columns: repeat(2, 1fr) !important;
            gap: 0.875rem !important;
          }
          /* Uniform size for ALL platform cards (a + button) */
          .nx-platform-grid > a,
          .nx-platform-grid > button {
            padding: 1.25rem 1rem !important;
            min-height: 120px !important;
            width: 100% !important;
            box-sizing: border-box !important;
          }
          .nx-platform-grid > a svg,
          .nx-platform-grid > button svg {
            width: 40px !important;
            height: 40px !important;
          }
        }
      `})]})}const Xi=[{id:"biometric",icon:t.jsxs("svg",{width:"22",height:"22",viewBox:"0 0 22 22",fill:"none",stroke:"currentColor",strokeWidth:"1.2",strokeLinecap:"round",strokeLinejoin:"round",children:[t.jsx("path",{d:"M6 7a5 5 0 0110 0"}),t.jsx("path",{d:"M8 11a3 3 0 016 0"}),t.jsx("path",{d:"M11 14v3"}),t.jsx("circle",{cx:"11",cy:"19",r:"1",fill:"currentColor",stroke:"none"})]}),title:"Biometría en el nodo",body:"Los registros biométricos nunca salen del nodo en formato legible."},{id:"encrypt",icon:t.jsxs("svg",{width:"22",height:"22",viewBox:"0 0 22 22",fill:"none",stroke:"currentColor",strokeWidth:"1.2",strokeLinecap:"round",strokeLinejoin:"round",children:[t.jsx("rect",{x:"5",y:"10",width:"12",height:"10",rx:"2"}),t.jsx("path",{d:"M8 10V7a3 3 0 016 0v3"}),t.jsx("circle",{cx:"11",cy:"15",r:"1.5",fill:"currentColor",stroke:"none"})]}),title:"Encriptación E2E",body:"Encriptación de extremo a extremo en cada transmisión de datos."},{id:"audit",icon:t.jsxs("svg",{width:"22",height:"22",viewBox:"0 0 22 22",fill:"none",stroke:"currentColor",strokeWidth:"1.2",strokeLinecap:"round",strokeLinejoin:"round",children:[t.jsx("path",{d:"M9 5H7a2 2 0 00-2 2v11a2 2 0 002 2h8a2 2 0 002-2V7a2 2 0 00-2-2h-2"}),t.jsx("rect",{x:"9",y:"3",width:"4",height:"4",rx:"1"}),t.jsx("line",{x1:"9",y1:"12",x2:"13",y2:"12"}),t.jsx("line",{x1:"9",y1:"16",x2:"11",y2:"16"})]}),title:"Auditoría total",body:"Sabes exactamente quién tocó qué dato y cuándo. Cada acción registrada."},{id:"compliance",icon:t.jsxs("svg",{width:"22",height:"22",viewBox:"0 0 22 22",fill:"none",stroke:"currentColor",strokeWidth:"1.2",strokeLinecap:"round",strokeLinejoin:"round",children:[t.jsx("path",{d:"M11 2L3 6v6c0 4.4 3.4 8.5 8 9.5 4.6-1 8-5.1 8-9.5V6l-8-4z"}),t.jsx("polyline",{points:"8 11 10 13 14 9"})]}),title:"MEN + SIC",body:"Cumplimiento con lineamientos de protección de datos del MEN y la SIC."},{id:"nothirdparty",icon:t.jsxs("svg",{width:"22",height:"22",viewBox:"0 0 22 22",fill:"none",stroke:"currentColor",strokeWidth:"1.2",strokeLinecap:"round",strokeLinejoin:"round",children:[t.jsx("circle",{cx:"11",cy:"11",r:"9"}),t.jsx("line",{x1:"4.9",y1:"4.9",x2:"17.1",y2:"17.1"})]}),title:"Sin terceros",body:"Sin venta de datos. Sin terceros con acceso. Sin publicidad de ningún tipo."}];function Fi(){return t.jsxs("svg",{className:"nx-shield",width:"120",height:"140",viewBox:"0 0 120 140",fill:"none","aria-hidden":"true",children:[t.jsx("path",{d:"M60 8L12 28v38c0 30 20 56 48 64 28-8 48-34 48-64V28L60 8z",stroke:"rgba(45,110,48,0.4)",strokeWidth:"1.5",fill:"none"}),t.jsx("path",{d:"M60 20L24 36v28c0 22 15 42 36 48 21-6 36-26 36-48V36L60 20z",stroke:"rgba(45,110,48,0.6)",strokeWidth:"1",fill:"rgba(45,110,48,0.04)"}),t.jsx("path",{d:"M44 68l12 12 20-20",stroke:"var(--nx-blue)",strokeWidth:"2",strokeLinecap:"round",strokeLinejoin:"round"}),t.jsx("circle",{cx:"60",cy:"68",r:"24",stroke:"rgba(45,110,48,0.15)",strokeWidth:"1",fill:"none"})]})}function qi(){const a=f.useRef(),e=f.useRef();return fr(e),Ct(a,e),t.jsxs("div",{ref:a,className:"section-wrapper",id:"seguridad",children:[t.jsx("section",{ref:e,className:"section-inner",style:{background:"linear-gradient(180deg, var(--nx-deep) 0%, var(--nx-void) 100%)",paddingLeft:"var(--nx-section-px)",paddingRight:"var(--nx-section-px)",display:"flex",alignItems:"center"},children:t.jsx("div",{style:{maxWidth:"1280px",margin:"0 auto",width:"100%"},children:t.jsxs("div",{className:"nx-security-layout",style:{display:"grid",gridTemplateColumns:"1fr 1.4fr",gap:"5rem",alignItems:"center"},children:[t.jsxs("div",{className:"nx-reveal",children:[t.jsx("div",{className:"nx-eyebrow",style:{marginBottom:"1rem"},children:"Seguridad"}),t.jsx("h2",{className:"nx-h2",style:{marginBottom:"1.25rem"},children:"Los datos de tus estudiantes no son un activo de nadie más."}),t.jsx("p",{className:"nx-body",style:{marginBottom:"2.5rem"},children:"NEXO fue diseñado desde cero con protección de datos como principio de arquitectura, no como característica adicional."}),t.jsx(Fi,{})]}),t.jsxs("div",{className:"nx-security-grid nx-reveal nx-reveal-delay-2",children:[Xi.map(({id:o,icon:r,title:n,body:i},s)=>t.jsxs("div",{className:`nx-card nx-reveal nx-reveal-delay-${s+1}`,style:{padding:"1.5rem"},children:[t.jsx("div",{className:"nx-icon",style:{marginBottom:"1rem"},children:r}),t.jsx("h3",{style:{fontSize:"0.9rem",fontWeight:700,color:"var(--nx-white)",marginBottom:"0.4rem"},children:n}),t.jsx("p",{style:{fontSize:"0.8rem",color:"var(--nx-muted)",lineHeight:1.6},children:i})]},o)),t.jsxs("div",{className:"nx-card nx-reveal nx-reveal-delay-5",style:{padding:"1.5rem",background:"rgba(45,110,48,0.05)",borderColor:"rgba(45,110,48,0.2)",display:"flex",flexDirection:"column",justifyContent:"center",alignItems:"center",textAlign:"center",gap:"0.5rem"},children:[t.jsx("div",{style:{fontSize:"1.5rem",fontWeight:800,color:"var(--nx-blue)"},children:"Ley 1581"}),t.jsx("div",{style:{fontSize:"0.72rem",color:"var(--nx-muted)",letterSpacing:"0.06em",textTransform:"uppercase"},children:"Protección de Datos Colombia"})]})]})]})})}),t.jsx("style",{children:`
        @media (max-width: 768px) {
          .nx-security-layout {
            grid-template-columns: 1fr !important;
            gap: 2rem !important;
          }
        }
      `})]})}const Yi={privacy:{title:"Política de Privacidad",body:`NEXO S.A.S., identificada con NIT [en trámite], con domicilio en Colombia, actúa como Responsable del Tratamiento de los datos personales recopilados a través de su plataforma de custodia educativa.

**1. Datos que recopilamos**

• Datos biométricos: huellas dactilares de estudiantes, procesadas y almacenadas en formato de plantilla cifrada (no como imagen).
• Datos de contacto: nombre, número de teléfono y correo electrónico de acudientes, docentes y directivos.
• Datos de asistencia y presencia: registros de entrada, salida, hora, ubicación dentro de la institución y eventos asociados.
• Datos del dispositivo institucional: identificadores del nodo, estado de batería, conectividad y logs operativos.

**2. Finalidad del tratamiento**

Los datos se recopilan exclusivamente para:
• Registrar y verificar la presencia de estudiantes en tiempo real.
• Notificar a los acudientes sobre inasistencias o eventos relevantes.
• Generar informes de trazabilidad para la institución educativa.
• Detectar patrones de comportamiento que requieran atención preventiva.
• Cumplir con obligaciones legales ante el MEN y entes de control.

**3. Responsable del tratamiento**

NEXO S.A.S. es el responsable del tratamiento. La institución educativa vinculada actúa como Encargada del Tratamiento en lo que respecta al acceso y uso de la información dentro de su jurisdicción.

**4. Protección de los datos**

• Encriptación de extremo a extremo en cada transmisión.
• Los datos biométricos se almacenan como plantillas matemáticas irreversibles.
• Acceso restringido por roles con autenticación segura.
• Infraestructura con conectividad M2M independiente (no depende de redes institucionales).
• Auditoría completa de cada acceso y modificación.

**5. No compartimos datos con terceros**

NEXO no vende, cede ni comparte datos personales con terceros comerciales. Los datos solo se comparten con la institución educativa vinculada y, cuando la ley lo exija, con autoridades competentes.

Esta política se rige por la Ley 1581 de 2012, el Decreto 1377 de 2013 y las directrices de la Superintendencia de Industria y Comercio (SIC).`},treatment:{title:"Tratamiento de Datos Personales",body:`En cumplimiento de la Ley Estatutaria 1581 de 2012 y el Decreto Reglamentario 1377 de 2013, NEXO S.A.S. informa las condiciones del tratamiento de datos personales:

**1. Base legal del tratamiento**

El tratamiento de datos personales se fundamenta en:
• Autorización previa, expresa e informada del titular o su representante legal (para menores de edad, el acudiente o padre de familia).
• Cumplimiento de una obligación legal (registros de asistencia escolar conforme a la normativa del MEN).
• Interés legítimo de la institución educativa en garantizar la seguridad y custodia de sus estudiantes.

**2. Derechos del titular**

De conformidad con el artículo 8 de la Ley 1581, los titulares tienen derecho a:
• Conocer, actualizar y rectificar sus datos personales.
• Solicitar prueba de la autorización otorgada.
• Ser informado sobre el uso que se ha dado a sus datos.
• Revocar la autorización y/o solicitar la supresión de los datos cuando no se respeten los principios, derechos y garantías constitucionales y legales.
• Acceder de forma gratuita a sus datos personales que hayan sido objeto de tratamiento.

**3. Canal para ejercer sus derechos**

Las solicitudes, consultas o reclamos pueden dirigirse a:
• Correo electrónico: jhonedisonalvarez21@gmail.com
• Teléfono/WhatsApp: +57 314 862 2367
• Tiempo de respuesta: 10 días hábiles para consultas, 15 días hábiles para reclamos (prorrogables conforme a la ley).

**4. Tiempo de retención**

• Los datos de asistencia y presencia se conservan durante la vigencia del contrato con la institución educativa y hasta 5 años después de su finalización, conforme a obligaciones legales de archivo institucional.
• Los datos biométricos (plantillas cifradas) se eliminan dentro de los 30 días siguientes a la desvinculación del estudiante de la institución o a la terminación del contrato.
• Los datos de contacto de acudientes se conservan mientras el estudiante esté vinculado a la institución.

**5. Transferencia y transmisión**

Los datos no se transfieren a terceros países. La transmisión se realiza únicamente entre el nodo (hardware), los servidores seguros de NEXO y la interfaz de la institución educativa autorizada.

Fecha de última actualización: mayo de 2026.`},terms:{title:"Términos de Uso",body:`Los presentes Términos de Uso regulan el acceso y utilización de la plataforma NEXO, operada por NEXO S.A.S., con domicilio en Colombia.

**1. Objeto**

NEXO es una plataforma de custodia educativa que integra hardware biométrico y software de gestión para el registro de presencia, trazabilidad y comunicación institucional en entidades educativas colombianas.

**2. Condiciones de uso**

• El acceso a la plataforma está reservado exclusivamente a instituciones educativas que hayan formalizado un contrato de vinculación con NEXO.
• Cada usuario (rector, coordinador, profesor, acudiente) accede con credenciales individuales e intransferibles.
• El usuario se compromete a no intentar acceder a información de otros usuarios o instituciones, modificar el hardware, o utilizar la plataforma para fines distintos a los previstos.
• El uso indebido de la plataforma podrá resultar en la suspensión inmediata del acceso.

**3. Límites de responsabilidad**

• NEXO garantiza el funcionamiento del hardware y software conforme a las especificaciones técnicas del contrato.
• NEXO no es responsable por interrupciones derivadas de fuerza mayor, daños intencionales al hardware por parte de terceros, ni por el uso indebido de la información por parte de usuarios autorizados de la institución.
• La institución educativa es responsable del uso que sus funcionarios hagan de la información accesible a través de la plataforma.

**4. Propiedad intelectual**

• El software, diseño, algoritmos, marca y todos los elementos de la plataforma NEXO son propiedad exclusiva de NEXO S.A.S.
• La institución educativa adquiere una licencia de uso no exclusiva, no transferible, vigente durante la duración del contrato.
• Queda prohibida la reproducción, distribución, ingeniería inversa o modificación de cualquier componente de la plataforma sin autorización escrita.

**5. Jurisdicción y ley aplicable**

• Estos términos se rigen por las leyes de la República de Colombia.
• Cualquier controversia será resuelta por los jueces y tribunales de la ciudad de Bogotá D.C., Colombia, salvo pacto arbitral incluido en el contrato de vinculación.

**6. Modificaciones**

NEXO se reserva el derecho de modificar estos términos. Las modificaciones serán notificadas a las instituciones vinculadas con al menos 15 días de anticipación.

Fecha de última actualización: mayo de 2026.`}};function Hi({type:a,onClose:e}){const o=f.useRef(),r=Yi[a];f.useEffect(()=>{const i=l=>{l.key==="Escape"&&e()};document.addEventListener("keydown",i);const s=window.scrollY;return document.body.style.position="fixed",document.body.style.top=`-${s}px`,document.body.style.left="0",document.body.style.right="0",document.body.style.overflow="hidden",()=>{document.removeEventListener("keydown",i),document.body.style.position="",document.body.style.top="",document.body.style.left="",document.body.style.right="",document.body.style.overflow="",window.scrollTo(0,s)}},[e]);const n=i=>{i.target===o.current&&e()};return r?t.jsxs("div",{ref:o,onClick:n,onTouchEnd:i=>{i.target===o.current&&e()},style:{position:"fixed",inset:0,zIndex:9999,background:"rgba(0, 0, 0, 0.75)",backdropFilter:"blur(4px)",WebkitBackdropFilter:"blur(4px)",display:"flex",alignItems:"center",justifyContent:"center",padding:"1rem",animation:"legalFadeIn 0.2s ease",touchAction:"none"},children:[t.jsxs("div",{role:"dialog","aria-modal":"true","aria-labelledby":"legal-modal-title",style:{background:"var(--nx-surface, #1a1d23)",border:"1px solid var(--nx-border, #2a2d35)",borderRadius:"1.25rem",maxWidth:"680px",width:"100%",maxHeight:"80vh",display:"flex",flexDirection:"column",overflow:"hidden"},children:[t.jsxs("div",{style:{display:"flex",alignItems:"center",justifyContent:"space-between",padding:"1.5rem 2rem",borderBottom:"1px solid var(--nx-border, #2a2d35)",flexShrink:0},children:[t.jsx("h3",{id:"legal-modal-title",style:{fontSize:"1.1rem",fontWeight:700,color:"var(--nx-white, #f0f2f5)",margin:0},children:r.title}),t.jsx("button",{onClick:e,onTouchEnd:i=>{i.preventDefault(),e()},type:"button","aria-label":"Cerrar",style:{background:"none",border:"1px solid var(--nx-border, #2a2d35)",borderRadius:"0.5rem",width:"44px",height:"44px",minWidth:"44px",display:"flex",alignItems:"center",justifyContent:"center",cursor:"pointer",color:"var(--nx-muted, #8a8f9a)",transition:"color 0.2s, border-color 0.2s",touchAction:"manipulation"},onMouseEnter:i=>{i.currentTarget.style.color="var(--nx-white)",i.currentTarget.style.borderColor="var(--nx-muted)"},onMouseLeave:i=>{i.currentTarget.style.color="var(--nx-muted)",i.currentTarget.style.borderColor="var(--nx-border)"},children:t.jsxs("svg",{width:"16",height:"16",viewBox:"0 0 16 16",fill:"none",stroke:"currentColor",strokeWidth:"1.5",strokeLinecap:"round",children:[t.jsx("line",{x1:"4",y1:"4",x2:"12",y2:"12"}),t.jsx("line",{x1:"12",y1:"4",x2:"4",y2:"12"})]})})]}),t.jsx("div",{style:{padding:"1.5rem 2rem",overflowY:"auto",WebkitOverflowScrolling:"touch",touchAction:"pan-y",fontSize:"0.85rem",lineHeight:1.75,color:"var(--nx-text, #c8ccd4)",fontFamily:"'Plus Jakarta Sans', sans-serif"},children:r.body.split(`
`).map((i,s)=>i.startsWith("**")&&i.endsWith("**")?t.jsx("h4",{style:{fontSize:"0.9rem",fontWeight:700,color:"var(--nx-white, #f0f2f5)",marginTop:"1.75rem",marginBottom:"0.75rem"},children:i.replace(/\*\*/g,"")},s):i.startsWith("• ")?t.jsxs("div",{style:{paddingLeft:"1rem",marginBottom:"0.35rem"},children:[t.jsx("span",{style:{color:"var(--nx-blue, #6b9fff)",marginRight:"0.5rem"},children:"•"}),i.slice(2)]},s):i.trim()===""?t.jsx("div",{style:{height:"0.75rem"}},s):t.jsx("p",{style:{margin:"0 0 0.5rem"},children:i},s))})]}),t.jsx("style",{children:`
        @keyframes legalFadeIn {
          from { opacity: 0; }
          to { opacity: 1; }
        }
      `})]}):null}function $i(){const a=f.useRef(),e=f.useRef(),o=f.useRef(),r=f.useRef(),n=f.useRef(),i=f.useRef(),s=f.useRef(),[l,d]=f.useState(!1),[h,m]=f.useState(null);return Ct(a,e,{isLast:!0}),f.useEffect(()=>{const x=a.current;if(!x)return;const g=o.current;if(window.innerWidth<=768&&g){_.set(g,{opacity:1}),_.set([r.current,n.current?.querySelectorAll("button, a")||[],i.current,s.current],{opacity:1,y:0});return}if(g){const M=g.innerHTML.split(/<br\s*\/?>/i);g.innerHTML=M.map(v=>v.trim().split("").map(L=>L===" "?'<span style="display:inline-block;width:0.28em">&nbsp;</span>':`<span style="display:inline-block;opacity:0;transform:scale(0.8)">${L}</span>`).join("")).join("<br/>")}const y=o.current?.querySelectorAll("span")||[],k=_.timeline({scrollTrigger:{trigger:x,start:"top 75%",toggleActions:"play none none none"}});return k.to(y,{opacity:1,scale:1,duration:.8,ease:"expo.out",stagger:.025}).fromTo(r.current,{opacity:0,y:18},{opacity:1,y:0,duration:.75,ease:"power3.out"},"-=0.4").fromTo(n.current?.querySelectorAll("button, a")||[],{opacity:0,scale:.94},{opacity:1,scale:1,duration:.6,ease:"power3.out",stagger:.15},.6).fromTo(i.current,{opacity:0},{opacity:1,duration:.5,ease:"power2.out"},"-=0.1").fromTo(s.current,{opacity:0,y:12},{opacity:1,y:0,duration:.5,ease:"power3.out"},"-=0.2"),()=>k.kill()},[]),t.jsxs(t.Fragment,{children:[l&&t.jsx(qo,{onClose:()=>d(!1)}),h&&t.jsx(Hi,{type:h,onClose:()=>m(null)}),t.jsxs("div",{ref:a,className:"section-wrapper",id:"contacto",children:[t.jsx("section",{ref:e,className:"section-inner",style:{paddingLeft:"var(--nx-section-px)",paddingRight:"var(--nx-section-px)",background:"linear-gradient(180deg, var(--nx-void) 0%, var(--nx-deep) 100%)",display:"flex",alignItems:"center"},children:t.jsxs("div",{style:{maxWidth:"760px",margin:"0 auto",textAlign:"center",width:"100%"},children:[t.jsx("div",{className:"nx-eyebrow",style:{display:"flex",justifyContent:"center",marginBottom:"1.25rem"},children:"El próximo paso"}),t.jsxs("h2",{ref:o,style:{fontSize:"clamp(1.6rem, 3.2vw, 2.4rem)",fontWeight:800,letterSpacing:"-0.03em",lineHeight:1.35,color:"var(--nx-white)",marginBottom:"1.25rem",overflow:"visible",paddingBottom:"0.25em",wordBreak:"break-word"},"aria-label":"El próximo semestre puede empezar diferente.",children:["El próximo semestre puede empezar",t.jsx("br",{}),"diferente."]}),t.jsx("p",{ref:r,className:"nx-body",style:{maxWidth:"520px",margin:"0 auto 3rem",opacity:0},children:"La implementación de NEXO es más rápida de lo que se imagina. Una conversación es suficiente para saber si la institución está lista para empezar el proceso."}),t.jsx("div",{ref:n,className:"nx-cta-buttons",style:{display:"flex",gap:"1rem",justifyContent:"center",flexWrap:"wrap",marginBottom:"1.5rem"},children:t.jsx("button",{id:"final-cta-primary",className:"nx-btn-primary",onClick:()=>d(!0),type:"button",style:{fontSize:"0.95rem",padding:"1rem 2rem",opacity:0},children:"Quiero que NEXO llegue a mi institución"})}),t.jsx("p",{ref:i,className:"nx-micro",style:{marginBottom:"4rem",opacity:0},children:"Sin costos de evaluación · Sin compromisos previos al contrato · Con acompañamiento desde el primer contacto"}),t.jsx("div",{className:"nx-divider",style:{marginBottom:"2.5rem"}}),t.jsxs("div",{ref:s,style:{display:"flex",gap:"2.5rem",justifyContent:"center",flexWrap:"wrap",alignItems:"center",opacity:0},children:[t.jsxs("a",{href:"mailto:jhonedisonalvarez21@gmail.com",style:{display:"flex",alignItems:"center",gap:"0.6rem",fontSize:"0.875rem",color:"var(--nx-muted)",transition:"color 0.25s"},onMouseEnter:x=>x.currentTarget.style.color="var(--nx-text)",onMouseLeave:x=>x.currentTarget.style.color="var(--nx-muted)",children:[t.jsxs("svg",{width:"16",height:"16",viewBox:"0 0 16 16",fill:"none",stroke:"currentColor",strokeWidth:"1.25",strokeLinecap:"round",strokeLinejoin:"round","aria-hidden":!0,children:[t.jsx("rect",{x:"1",y:"3",width:"14",height:"10",rx:"1.5"}),t.jsx("polyline",{points:"1,3 8,9 15,3"})]}),"jhonedisonalvarez21@gmail.com"]}),t.jsx("div",{style:{width:"1px",height:"16px",background:"var(--nx-border)"},"aria-hidden":!0}),t.jsxs("a",{href:"https://wa.me/573148622367",target:"_blank",rel:"noopener noreferrer",style:{display:"flex",alignItems:"center",gap:"0.6rem",fontSize:"0.875rem",color:"var(--nx-muted)",transition:"color 0.25s"},onMouseEnter:x=>x.currentTarget.style.color="var(--nx-text)",onMouseLeave:x=>x.currentTarget.style.color="var(--nx-muted)",children:[t.jsx("svg",{width:"16",height:"16",viewBox:"0 0 16 16",fill:"none",stroke:"currentColor",strokeWidth:"1.25",strokeLinecap:"round",strokeLinejoin:"round","aria-hidden":!0,children:t.jsx("path",{d:"M14 10.67c0 .23-.05.45-.16.66a2.74 2.74 0 01-.42.6c-.27.3-.56.45-.88.46-.23 0-.47-.05-.73-.16L8 9.7 3.2 12.23a1.8 1.8 0 01-.73.16 1.4 1.4 0 01-.88-.46 2.74 2.74 0 01-.42-.6A1.6 1.6 0 011 10.67V3.4c0-.62.22-1.15.67-1.6A2.17 2.17 0 013.27 1.1h9.46c.62 0 1.15.23 1.6.7.45.45.67.98.67 1.6v7.27z"})}),"+57 314 862 2367 (WhatsApp)"]})]}),t.jsxs("div",{style:{marginTop:"2.5rem",display:"flex",justifyContent:"center",gap:"0.5rem",flexWrap:"wrap",fontSize:"0.75rem",color:"var(--nx-muted)"},children:[t.jsx("button",{type:"button",onClick:()=>m("privacy"),style:{background:"none",border:"none",color:"var(--nx-muted)",cursor:"pointer",fontSize:"0.75rem",padding:"0.25rem 0.4rem",transition:"color 0.2s"},onMouseEnter:x=>x.currentTarget.style.color="var(--nx-text)",onMouseLeave:x=>x.currentTarget.style.color="var(--nx-muted)",children:"Política de privacidad"}),t.jsx("span",{style:{color:"var(--nx-border)"},children:"|"}),t.jsx("button",{type:"button",onClick:()=>m("treatment"),style:{background:"none",border:"none",color:"var(--nx-muted)",cursor:"pointer",fontSize:"0.75rem",padding:"0.25rem 0.4rem",transition:"color 0.2s"},onMouseEnter:x=>x.currentTarget.style.color="var(--nx-text)",onMouseLeave:x=>x.currentTarget.style.color="var(--nx-muted)",children:"Tratamiento de datos"}),t.jsx("span",{style:{color:"var(--nx-border)"},children:"|"}),t.jsx("button",{type:"button",onClick:()=>m("terms"),style:{background:"none",border:"none",color:"var(--nx-muted)",cursor:"pointer",fontSize:"0.75rem",padding:"0.25rem 0.4rem",transition:"color 0.2s"},onMouseEnter:x=>x.currentTarget.style.color="var(--nx-text)",onMouseLeave:x=>x.currentTarget.style.color="var(--nx-muted)",children:"Términos de uso"})]})]})}),t.jsx("style",{children:`
          @media (max-width: 768px) {
            #contacto .section-inner {
              text-align: left !important;
            }
            #contacto h2 {
              font-size: clamp(1.5rem, 6vw, 1.85rem) !important;
              overflow: visible !important;
              padding-bottom: 0.3em !important;
            }
            .nx-cta-buttons {
              flex-direction: column !important;
              align-items:    stretch !important;
              gap:            0.875rem !important;
            }
            .nx-cta-buttons button,
            .nx-cta-buttons a {
              width:            100% !important;
              justify-content:  center !important;
              text-align:       center !important;
            }
            #contacto [style*="flexWrap"] {
              justify-content: flex-start !important;
              gap: 1rem !important;
            }
          }
        `})]})]})}function Ui(){const a=new Date().getFullYear();return t.jsx("footer",{className:"nx-footer",style:{paddingTop:"3rem",paddingBottom:"3rem",paddingLeft:"var(--nx-section-px)",paddingRight:"var(--nx-section-px)"},children:t.jsxs("div",{style:{maxWidth:"1280px",margin:"0 auto",display:"flex",alignItems:"center",justifyContent:"space-between",gap:"2rem",flexWrap:"wrap"},children:[t.jsx("span",{style:{fontWeight:800,fontSize:"1rem",letterSpacing:"-0.02em",color:"var(--nx-white)"},children:"NEXO"}),t.jsx("nav",{"aria-label":"Legal",children:t.jsx("ul",{style:{display:"flex",gap:"1.75rem",listStyle:"none",flexWrap:"wrap"},children:[{label:"Política de privacidad",href:"#privacidad"},{label:"Tratamiento de datos",href:"#datos"},{label:"Términos de uso",href:"#terminos"}].map(({label:e,href:o})=>t.jsx("li",{children:t.jsx("a",{href:o,style:{fontSize:"0.78rem",color:"var(--nx-muted-2)",transition:"color 0.2s"},onMouseEnter:r=>r.target.style.color="var(--nx-muted)",onMouseLeave:r=>r.target.style.color="var(--nx-muted-2)",children:e})},e))})}),t.jsxs("span",{style:{fontSize:"0.75rem",color:"var(--nx-muted-2)"},children:["© ",a," NEXO. Todos los derechos reservados."]})]})})}function Gi(){const a=f.useRef(),e=f.useRef();return f.useEffect(()=>{if(window.matchMedia("(pointer: coarse)").matches)return;document.body.classList.add("custom-cursor-active");const o=a.current,r=e.current;if(!o||!r)return;let n=0,i=0,s=0,l=0,d=0;const h=.12,m=k=>{n=k.clientX,i=k.clientY,_.set(o,{x:n,y:i})};document.addEventListener("mousemove",m);const x=()=>{s+=(n-s)*h,l+=(i-l)*h,_.set(r,{x:s,y:l}),d=requestAnimationFrame(x)};x();const g=document.querySelectorAll('button, a, [data-cursor-expand], [role="button"], .nx-hotspot, .nx-tab'),c=()=>{r.style.width="48px",r.style.height="48px",r.style.borderColor="rgba(99, 179, 237, 0.8)"},y=()=>{r.style.width="32px",r.style.height="32px",r.style.borderColor="rgba(255,255,255,0.5)"};return g.forEach(k=>{k.addEventListener("mouseenter",c),k.addEventListener("mouseleave",y)}),()=>{cancelAnimationFrame(d),document.body.classList.remove("custom-cursor-active"),document.removeEventListener("mousemove",m),g.forEach(k=>{k.removeEventListener("mouseenter",c),k.removeEventListener("mouseleave",y)})}},[]),typeof window<"u"&&window.matchMedia("(pointer: coarse)").matches?null:t.jsxs(t.Fragment,{children:[t.jsx("div",{id:"cursor-dot",ref:a}),t.jsx("div",{id:"cursor-ring",ref:e})]})}const bn="nexo_cookie_consent",po="1.0",Vi={necessary:{id:"necessary",label:"Cookies necesarias",description:"Esenciales para el funcionamiento básico del sitio. No se pueden desactivar.",required:!0},analytics:{id:"analytics",label:"Cookies analíticas",description:"Nos ayudan a entender cómo los visitantes interactúan con el sitio para mejorar la experiencia.",required:!1},marketing:{id:"marketing",label:"Cookies de marketing",description:"Permiten mostrar contenido y anuncios relevantes según tus intereses.",required:!1},preferences:{id:"preferences",label:"Cookies de preferencias",description:"Recuerdan tus configuraciones y personalizaciones para visitas futuras.",required:!1}};function Ki(){const[a,e]=f.useState(null),[o,r]=f.useState(!1),[n,i]=f.useState(!1);f.useEffect(()=>{const g=localStorage.getItem(bn);if(g)try{const c=JSON.parse(g);if(c.version===po){e(c),r(!1);return}}catch{}setTimeout(()=>r(!0),800)},[]);const s=g=>{const c={version:po,timestamp:new Date().toISOString(),categories:g};localStorage.setItem(bn,JSON.stringify(c)),e(c),r(!1),i(!1)};return{consent:a,showBanner:o,showManager:n,setShowManager:i,acceptAll:()=>{s({necessary:!0,analytics:!0,marketing:!0,preferences:!0})},rejectAll:()=>{s({necessary:!0,analytics:!1,marketing:!1,preferences:!1})},saveCustom:g=>{s({necessary:!0,...g})},resetConsent:()=>{localStorage.removeItem(bn),e(null),r(!0)},hasConsent:g=>a?.categories?.[g]===!0}}/**
 * @license lucide-react v1.16.0 - ISC
 *
 * This source code is licensed under the ISC license.
 * See the LICENSE file in the root directory of this source tree.
 */const Yo=(...a)=>a.filter((e,o,r)=>!!e&&e.trim()!==""&&r.indexOf(e)===o).join(" ").trim();/**
 * @license lucide-react v1.16.0 - ISC
 *
 * This source code is licensed under the ISC license.
 * See the LICENSE file in the root directory of this source tree.
 */const Ji=a=>a.replace(/([a-z0-9])([A-Z])/g,"$1-$2").toLowerCase();/**
 * @license lucide-react v1.16.0 - ISC
 *
 * This source code is licensed under the ISC license.
 * See the LICENSE file in the root directory of this source tree.
 */const Zi=a=>a.replace(/^([A-Z])|[\s-_]+(\w)/g,(e,o,r)=>r?r.toUpperCase():o.toLowerCase());/**
 * @license lucide-react v1.16.0 - ISC
 *
 * This source code is licensed under the ISC license.
 * See the LICENSE file in the root directory of this source tree.
 */const fo=a=>{const e=Zi(a);return e.charAt(0).toUpperCase()+e.slice(1)};/**
 * @license lucide-react v1.16.0 - ISC
 *
 * This source code is licensed under the ISC license.
 * See the LICENSE file in the root directory of this source tree.
 */var vn={xmlns:"http://www.w3.org/2000/svg",width:24,height:24,viewBox:"0 0 24 24",fill:"none",stroke:"currentColor",strokeWidth:2,strokeLinecap:"round",strokeLinejoin:"round"};/**
 * @license lucide-react v1.16.0 - ISC
 *
 * This source code is licensed under the ISC license.
 * See the LICENSE file in the root directory of this source tree.
 */const Qi=a=>{for(const e in a)if(e.startsWith("aria-")||e==="role"||e==="title")return!0;return!1},ea=f.createContext({}),ta=()=>f.useContext(ea),ra=f.forwardRef(({color:a,size:e,strokeWidth:o,absoluteStrokeWidth:r,className:n="",children:i,iconNode:s,...l},d)=>{const{size:h=24,strokeWidth:m=2,absoluteStrokeWidth:x=!1,color:g="currentColor",className:c=""}=ta()??{},y=r??x?Number(o??m)*24/Number(e??h):o??m;return f.createElement("svg",{ref:d,...vn,width:e??h??vn.width,height:e??h??vn.height,stroke:a??g,strokeWidth:y,className:Yo("lucide",c,n),...!i&&!Qi(l)&&{"aria-hidden":"true"},...l},[...s.map(([k,M])=>f.createElement(k,M)),...Array.isArray(i)?i:[i]])});/**
 * @license lucide-react v1.16.0 - ISC
 *
 * This source code is licensed under the ISC license.
 * See the LICENSE file in the root directory of this source tree.
 */const Xn=(a,e)=>{const o=f.forwardRef(({className:r,...n},i)=>f.createElement(ra,{ref:i,iconNode:e,className:Yo(`lucide-${Ji(fo(a))}`,`lucide-${a}`,r),...n}));return o.displayName=fo(a),o};/**
 * @license lucide-react v1.16.0 - ISC
 *
 * This source code is licensed under the ISC license.
 * See the LICENSE file in the root directory of this source tree.
 */const na=[["path",{d:"M12 2a10 10 0 1 0 10 10 4 4 0 0 1-5-5 4 4 0 0 1-5-5",key:"laymnq"}],["path",{d:"M8.5 8.5v.01",key:"ue8clq"}],["path",{d:"M16 15.5v.01",key:"14dtrp"}],["path",{d:"M12 12v.01",key:"u5ubse"}],["path",{d:"M11 17v.01",key:"1hyl5a"}],["path",{d:"M7 14v.01",key:"uct60s"}]],oa=Xn("cookie",na);/**
 * @license lucide-react v1.16.0 - ISC
 *
 * This source code is licensed under the ISC license.
 * See the LICENSE file in the root directory of this source tree.
 */const ia=[["path",{d:"M20 13c0 5-3.5 7.5-7.66 8.95a1 1 0 0 1-.67-.01C7.5 20.5 4 18 4 13V6a1 1 0 0 1 1-1c2 0 4.5-1.2 6.24-2.72a1.17 1.17 0 0 1 1.52 0C14.51 3.81 17 5 19 5a1 1 0 0 1 1 1z",key:"oel41y"}]],aa=Xn("shield",ia);/**
 * @license lucide-react v1.16.0 - ISC
 *
 * This source code is licensed under the ISC license.
 * See the LICENSE file in the root directory of this source tree.
 */const sa=[["path",{d:"M18 6 6 18",key:"1bl5f8"}],["path",{d:"m6 6 12 12",key:"d8bk6v"}]],la=Xn("x",sa),ca=({onAcceptAll:a,onRejectAll:e,onManage:o})=>t.jsxs("div",{className:"cookie-banner",children:[t.jsx("style",{children:`
        @keyframes slideUpBanner {
          from {
            transform: translateY(120%);
            opacity: 0;
          }
          to {
            transform: translateY(0);
            opacity: 1;
          }
        }

        .cookie-banner {
          position: fixed;
          bottom: 1.5rem;
          left: 1.5rem;
          max-width: 420px;
          background: #ffffff;
          border: 1px solid rgba(45, 110, 48, 0.2);
          border-radius: 1rem;
          padding: 1.5rem;
          box-shadow: 0 8px 40px rgba(0, 0, 0, 0.12);
          z-index: 9999;
          animation: slideUpBanner 0.4s cubic-bezier(0.16, 1, 0.3, 1);
        }

        @media (max-width: 640px) {
          .cookie-banner {
            left: 1rem;
            right: 1rem;
            bottom: 1rem;
            max-width: none;
          }
        }

        .cookie-banner__header {
          display: flex;
          align-items: flex-start;
          gap: 0.875rem;
          margin-bottom: 1rem;
        }

        .cookie-banner__icon {
          flex-shrink: 0;
          width: 2.5rem;
          height: 2.5rem;
          background: rgba(26, 74, 31, 0.1);
          border-radius: 0.625rem;
          display: flex;
          align-items: center;
          justify-content: center;
          color: #1a4a1f;
        }

        .cookie-banner__content h3 {
          font-size: 1rem;
          font-weight: 700;
          color: #0f2d12;
          margin: 0 0 0.25rem 0;
        }

        .cookie-banner__content p {
          font-size: 0.875rem;
          line-height: 1.5;
          color: #0f2d12;
          opacity: 0.8;
          margin: 0;
        }

        .cookie-banner__actions {
          display: flex;
          flex-direction: column;
          gap: 0.625rem;
          margin-top: 1.25rem;
        }

        .cookie-banner__btn {
          width: 100%;
          padding: 0.75rem 1rem;
          border-radius: 0.625rem;
          font-size: 0.875rem;
          font-weight: 600;
          cursor: pointer;
          transition: all 0.2s cubic-bezier(0.16, 1, 0.3, 1);
          border: none;
          outline: none;
        }

        .cookie-banner__btn--primary {
          background: #1a4a1f;
          color: #ffffff;
        }

        .cookie-banner__btn--primary:hover {
          background: #143a18;
          transform: translateY(-1px);
          box-shadow: 0 4px 12px rgba(26, 74, 31, 0.3);
        }

        .cookie-banner__btn--primary:active {
          transform: translateY(0);
        }

        .cookie-banner__btn--ghost {
          background: transparent;
          color: #1a4a1f;
          border: 1.5px solid #1a4a1f;
        }

        .cookie-banner__btn--ghost:hover {
          background: rgba(26, 74, 31, 0.05);
        }

        .cookie-banner__link {
          text-align: center;
          margin-top: 0.5rem;
        }

        .cookie-banner__link button {
          background: none;
          border: none;
          color: #1a4a1f;
          font-size: 0.813rem;
          font-weight: 500;
          text-decoration: underline;
          cursor: pointer;
          padding: 0;
        }

        .cookie-banner__link button:hover {
          opacity: 0.8;
        }
      `}),t.jsxs("div",{className:"cookie-banner__header",children:[t.jsx("div",{className:"cookie-banner__icon",children:t.jsx(oa,{size:20})}),t.jsxs("div",{className:"cookie-banner__content",children:[t.jsx("h3",{children:"Usamos cookies"}),t.jsx("p",{children:"Utilizamos cookies para mejorar tu experiencia, analizar el tráfico y personalizar el contenido. Puedes aceptar todas o administrar tus preferencias."})]})]}),t.jsxs("div",{className:"cookie-banner__actions",children:[t.jsx("button",{onClick:a,className:"cookie-banner__btn cookie-banner__btn--primary",children:"Aceptar todas"}),t.jsx("button",{onClick:e,className:"cookie-banner__btn cookie-banner__btn--ghost",children:"Solo necesarias"})]}),t.jsx("div",{className:"cookie-banner__link",children:t.jsx("button",{onClick:o,children:"Administrar cookies"})})]}),da=({onSave:a,onAcceptAll:e,onClose:o,initialValues:r})=>{const[n,i]=f.useState({necessary:!0,analytics:r?.analytics??!1,marketing:r?.marketing??!1,preferences:r?.preferences??!1});f.useEffect(()=>(document.body.style.overflow="hidden",()=>{document.body.style.overflow="unset"}),[]);const s=d=>{d!=="necessary"&&i(h=>({...h,[d]:!h[d]}))},l=()=>{a(n)};return t.jsxs("div",{className:"cookie-manager-overlay",children:[t.jsx("style",{children:`
        @keyframes fadeInOverlay {
          from { opacity: 0; }
          to { opacity: 1; }
        }

        @keyframes scaleInModal {
          from {
            opacity: 0;
            transform: scale(0.95);
          }
          to {
            opacity: 1;
            transform: scale(1);
          }
        }

        .cookie-manager-overlay {
          position: fixed;
          inset: 0;
          background: rgba(0, 0, 0, 0.5);
          backdrop-filter: blur(4px);
          display: flex;
          align-items: center;
          justify-content: center;
          z-index: 10000;
          padding: 1rem;
          animation: fadeInOverlay 0.2s ease-out;
        }

        .cookie-manager-modal {
          background: #ffffff;
          border-radius: 1.25rem;
          max-width: 560px;
          width: 100%;
          max-height: 90vh;
          overflow-y: auto;
          box-shadow: 0 20px 60px rgba(0, 0, 0, 0.3);
          animation: scaleInModal 0.3s cubic-bezier(0.16, 1, 0.3, 1);
        }

        .cookie-manager-header {
          padding: 1.5rem;
          border-bottom: 1px solid rgba(45, 110, 48, 0.1);
          display: flex;
          align-items: center;
          justify-content: space-between;
        }

        .cookie-manager-header h2 {
          font-size: 1.25rem;
          font-weight: 700;
          color: #0f2d12;
          margin: 0;
        }

        .cookie-manager-close {
          width: 2rem;
          height: 2rem;
          border-radius: 0.5rem;
          background: transparent;
          border: none;
          color: #0f2d12;
          cursor: pointer;
          display: flex;
          align-items: center;
          justify-content: center;
          transition: background 0.2s;
        }

        .cookie-manager-close:hover {
          background: rgba(45, 110, 48, 0.1);
        }

        .cookie-manager-body {
          padding: 1.5rem;
        }

        .cookie-category {
          padding: 1.25rem;
          background: rgba(45, 110, 48, 0.03);
          border-radius: 0.875rem;
          margin-bottom: 0.875rem;
          border: 1px solid rgba(45, 110, 48, 0.1);
        }

        .cookie-category-header {
          display: flex;
          align-items: center;
          justify-content: space-between;
          margin-bottom: 0.5rem;
        }

        .cookie-category-title {
          font-size: 0.938rem;
          font-weight: 600;
          color: #0f2d12;
          margin: 0;
        }

        .cookie-category-desc {
          font-size: 0.813rem;
          line-height: 1.5;
          color: #0f2d12;
          opacity: 0.7;
          margin: 0;
        }

        .toggle-switch {
          position: relative;
          width: 44px;
          height: 24px;
          flex-shrink: 0;
        }

        .toggle-switch input {
          opacity: 0;
          width: 0;
          height: 0;
        }

        .toggle-slider {
          position: absolute;
          cursor: pointer;
          top: 0;
          left: 0;
          right: 0;
          bottom: 0;
          background-color: #e0e0e0;
          transition: 0.3s;
          border-radius: 12px;
        }

        .toggle-slider:before {
          position: absolute;
          content: "";
          height: 18px;
          width: 18px;
          left: 3px;
          bottom: 3px;
          background-color: white;
          transition: 0.3s;
          border-radius: 50%;
        }

        input:checked + .toggle-slider {
          background-color: #2d6e30;
        }

        input:checked + .toggle-slider:before {
          transform: translateX(20px);
        }

        input:disabled + .toggle-slider {
          opacity: 0.5;
          cursor: not-allowed;
        }

        .cookie-manager-footer {
          padding: 1.5rem;
          border-top: 1px solid rgba(45, 110, 48, 0.1);
          display: flex;
          gap: 0.75rem;
          flex-wrap: wrap;
        }

        .cookie-manager-btn {
          flex: 1;
          padding: 0.875rem 1.25rem;
          border-radius: 0.75rem;
          font-size: 0.875rem;
          font-weight: 600;
          cursor: pointer;
          transition: all 0.2s;
          border: none;
          min-width: 140px;
        }

        .cookie-manager-btn--primary {
          background: #1a4a1f;
          color: #ffffff;
        }

        .cookie-manager-btn--primary:hover {
          background: #143a18;
          transform: translateY(-1px);
          box-shadow: 0 4px 12px rgba(26, 74, 31, 0.3);
        }

        .cookie-manager-btn--secondary {
          background: rgba(26, 74, 31, 0.1);
          color: #1a4a1f;
        }

        .cookie-manager-btn--secondary:hover {
          background: rgba(26, 74, 31, 0.15);
        }
      `}),t.jsxs("div",{className:"cookie-manager-modal",children:[t.jsxs("div",{className:"cookie-manager-header",children:[t.jsx("h2",{children:"Preferencias de cookies"}),t.jsx("button",{onClick:o,className:"cookie-manager-close","aria-label":"Cerrar",children:t.jsx(la,{size:20})})]}),t.jsx("div",{className:"cookie-manager-body",children:Object.values(Vi).map(d=>t.jsxs("div",{className:"cookie-category",children:[t.jsxs("div",{className:"cookie-category-header",children:[t.jsx("h3",{className:"cookie-category-title",children:d.label}),t.jsxs("label",{className:"toggle-switch",children:[t.jsx("input",{type:"checkbox",checked:n[d.id],disabled:d.required,onChange:()=>s(d.id)}),t.jsx("span",{className:"toggle-slider"})]})]}),t.jsx("p",{className:"cookie-category-desc",children:d.description})]},d.id))}),t.jsxs("div",{className:"cookie-manager-footer",children:[t.jsx("button",{onClick:l,className:"cookie-manager-btn cookie-manager-btn--primary",children:"Guardar preferencias"}),t.jsx("button",{onClick:e,className:"cookie-manager-btn cookie-manager-btn--secondary",children:"Aceptar todas"})]})]})]})},ua=({onClick:a})=>t.jsxs("button",{onClick:a,className:"cookie-floating-btn","aria-label":"Gestionar cookies",children:[t.jsx("style",{children:`
        .cookie-floating-btn {
          position: fixed;
          bottom: 1.5rem;
          left: 1.5rem;
          width: 48px;
          height: 48px;
          border-radius: 50%;
          background: #ffffff;
          border: 1px solid rgba(45, 110, 48, 0.3);
          box-shadow: 0 2px 12px rgba(0, 0, 0, 0.1);
          display: flex;
          align-items: center;
          justify-content: center;
          cursor: pointer;
          z-index: 9998;
          transition: all 0.2s cubic-bezier(0.16, 1, 0.3, 1);
          color: #1a4a1f;
        }

        .cookie-floating-btn:hover {
          transform: scale(1.1);
          box-shadow: 0 4px 20px rgba(0, 0, 0, 0.15);
          border-color: #1a4a1f;
        }

        .cookie-floating-btn:active {
          transform: scale(1.05);
        }

        .cookie-floating-btn::before {
          content: attr(aria-label);
          position: absolute;
          left: 100%;
          margin-left: 0.75rem;
          padding: 0.5rem 0.75rem;
          background: #0f2d12;
          color: #ffffff;
          font-size: 0.75rem;
          font-weight: 500;
          white-space: nowrap;
          border-radius: 0.375rem;
          opacity: 0;
          pointer-events: none;
          transition: opacity 0.2s;
        }

        .cookie-floating-btn:hover::before {
          opacity: 1;
        }

        @media (max-width: 640px) {
          .cookie-floating-btn {
            bottom: 1rem;
            left: 1rem;
            width: 44px;
            height: 44px;
          }

          .cookie-floating-btn::before {
            display: none;
          }
        }
      `}),t.jsx(aa,{size:20})]});_.registerPlugin(A);function pa(){const a=Ki();return f.useEffect(()=>{const e=setTimeout(()=>{A.refresh()},1200);let o;const r=()=>{clearTimeout(o),o=setTimeout(()=>{A.refresh()},250)};return window.addEventListener("resize",r),()=>{clearTimeout(e),clearTimeout(o),window.removeEventListener("resize",r)}},[]),t.jsxs(t.Fragment,{children:[t.jsx(Gi,{}),t.jsx(yi,{}),t.jsxs("main",{id:"nx-landing",children:[t.jsx(Ri,{}),"         "," ",t.jsx(Ti,{}),"      ",t.jsx(Li,{}),"   ",t.jsx(Ni,{}),"    ",t.jsx(Ai,{}),"         ",t.jsx(Oi,{}),"        ",t.jsx(Ii,{}),"     ",t.jsx(qi,{}),"     ",t.jsx($i,{}),"     "]}),t.jsx(Ui,{}),a.showBanner&&t.jsx(ca,{onAcceptAll:a.acceptAll,onRejectAll:a.rejectAll,onManage:()=>a.setShowManager(!0)}),a.showManager&&t.jsx(da,{onSave:a.saveCustom,onAcceptAll:a.acceptAll,onClose:()=>a.setShowManager(!1),initialValues:a.consent?.categories}),a.consent&&!a.showBanner&&t.jsx(ua,{onClick:()=>a.setShowManager(!0)})]})}function fa(){const a=f.useRef(),[e,o]=f.useState(0),r=f.useRef(!1),n=()=>{r.current||(r.current=!0,a.current&&_.to(a.current,{yPercent:-100,duration:1.1,ease:"power4.inOut",delay:.15,onComplete:()=>{a.current&&(a.current.style.display="none")}}))};return f.useEffect(()=>{let i=null;const s=1600,l=h=>{i||(i=h);const m=Math.min((h-i)/s*100,100);o(Math.round(m)),m<100?requestAnimationFrame(l):n()};requestAnimationFrame(l);const d=setTimeout(n,4e3);return()=>clearTimeout(d)},[]),t.jsxs("div",{ref:a,style:{position:"fixed",inset:0,zIndex:9999,background:"#f7fcf7",display:"flex",flexDirection:"column",alignItems:"center",justifyContent:"center",gap:"1.5rem"},children:[t.jsxs("svg",{width:"52",height:"52",viewBox:"0 0 64 64",fill:"none",style:{animation:"nxPulse 1.8s ease-in-out infinite"},"aria-label":"NEXO",children:[t.jsx("rect",{x:"2",y:"2",width:"60",height:"60",rx:"12",stroke:"#2d6e30",strokeWidth:"2"}),t.jsx("path",{d:"M14 50L32 14L50 50",stroke:"#2d6e30",strokeWidth:"3",strokeLinecap:"round",strokeLinejoin:"round"}),t.jsx("path",{d:"M20 38H44",stroke:"#2d6e30",strokeWidth:"2",strokeLinecap:"round"})]}),t.jsx("div",{style:{width:"96px",height:"1px",background:"rgba(200, 230, 200, 0.6)",borderRadius:"1px",overflow:"hidden"},children:t.jsx("div",{style:{height:"100%",background:"linear-gradient(90deg, #2d6e30, rgba(45, 110, 48, 0.45))",borderRadius:"1px",width:`${e}%`,transition:"width 0.1s linear",boxShadow:"0 0 8px rgba(45, 110, 48, 0.5)"}})}),t.jsxs("span",{style:{fontFamily:"'Plus Jakarta Sans', sans-serif",fontSize:"0.62rem",letterSpacing:"0.18em",color:"#4a6e4c",textTransform:"uppercase"},children:[e,"%"]}),t.jsx("style",{children:`
        @keyframes nxPulse {
          0%, 100% { opacity: 1;   transform: scale(1); }
          50%       { opacity: 0.45; transform: scale(0.92); }
        }
      `})]})}class ma extends xo.Component{constructor(e){super(e),this.state={hasError:!1,error:null}}static getDerivedStateFromError(e){return{hasError:!0,error:e}}componentDidCatch(e,o){console.error("ErrorBoundary caught an error:",e,o)}render(){return this.state.hasError?t.jsxs("div",{style:{position:"fixed",inset:0,zIndex:99999,background:"#0a0f0d",color:"#ff5252",padding:"2rem",fontFamily:"monospace",display:"flex",flexDirection:"column",gap:"1rem",overflow:"auto"},children:[t.jsx("h2",{style:{color:"#ff5252",margin:0},children:"⚠️ NEXO Error Boundary"}),t.jsx("p",{style:{color:"#fff",fontSize:"1rem"},children:"El sitio experimentó un error al renderizar:"}),t.jsx("pre",{style:{background:"#111614",padding:"1rem",borderRadius:"4px",border:"1px solid #ff5252",color:"#ff8a80",whiteSpace:"pre-wrap"},children:this.state.error?.toString()}),t.jsx("p",{style:{color:"#8da898",fontSize:"0.85rem"},children:"Revisa la consola del navegador para más detalles."})]}):this.props.children}}function ha(){return t.jsx(ma,{children:t.jsxs(f.Suspense,{fallback:null,children:[t.jsx(fa,{}),t.jsx(pa,{})]})})}Zo.createRoot(document.getElementById("root")).render(t.jsx(xo.StrictMode,{children:t.jsx(ha,{})}));
