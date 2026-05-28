import{j as t,e as mo,f as Ho,r as m,B as $o,V as on,u as Nn,c as Go,M as Uo,C as Vo,E as Ko,a as Jo,b as ho,S as go,R as xo,d as Zo}from"./three-vendor-CXH9RDG6.js";import{g as _}from"./gsap-vendor-SFc2wnMY.js";(function(){const e=document.createElement("link").relList;if(e&&e.supports&&e.supports("modulepreload"))return;for(const n of document.querySelectorAll('link[rel="modulepreload"]'))r(n);new MutationObserver(n=>{for(const i of n)if(i.type==="childList")for(const l of i.addedNodes)l.tagName==="LINK"&&l.rel==="modulepreload"&&r(l)}).observe(document,{childList:!0,subtree:!0});function o(n){const i={};return n.integrity&&(i.integrity=n.integrity),n.referrerPolicy&&(i.referrerPolicy=n.referrerPolicy),n.crossOrigin==="use-credentials"?i.credentials="include":n.crossOrigin==="anonymous"?i.credentials="omit":i.credentials="same-origin",i}function r(n){if(n.ep)return;n.ep=!0;const i=o(n);fetch(n.href,i)}})();function Qo(a,e){for(var o=0;o<e.length;o++){var r=e[o];r.enumerable=r.enumerable||!1,r.configurable=!0,"value"in r&&(r.writable=!0),Object.defineProperty(a,r.key,r)}}function ei(a,e,o){return e&&Qo(a.prototype,e),a}/*!
 * Observer 3.15.0
 * https://gsap.com
 *
 * @license Copyright 2008-2026, GreenSock. All rights reserved.
 * Subject to the terms at https://gsap.com/standard-license
 * @author: Jack Doyle, jack@greensock.com
*/var ke,Zr,Je,At,Pt,lr,yo,Yt,cr,bo,jt,lt,vo,wo=function(){return ke||typeof window<"u"&&(ke=window.gsap)&&ke.registerPlugin&&ke},ko=1,sr=[],B=[],mt=[],Sr=Date.now,wn=function(e,o){return o},ti=function(){var e=cr.core,o=e.bridge||{},r=e._scrollers,n=e._proxies;r.push.apply(r,B),n.push.apply(n,mt),B=r,mt=n,wn=function(l,s){return o[l](s)}},Ot=function(e,o){return~mt.indexOf(e)&&mt[mt.indexOf(e)+1][o]},Er=function(e){return!!~bo.indexOf(e)},We=function(e,o,r,n,i){return e.addEventListener(o,r,{passive:n!==!1,capture:!!i})},Oe=function(e,o,r,n){return e.removeEventListener(o,r,!!n)},Ir="scrollLeft",Xr="scrollTop",kn=function(){return jt&&jt.isPressed||B.cache++},an=function(e,o){var r=function n(i){if(i||i===0){ko&&(Je.history.scrollRestoration="manual");var l=jt&&jt.isPressed;i=n.v=Math.round(i)||(jt&&jt.iOS?1:0),e(i),n.cacheID=B.cache,l&&wn("ss",i)}else(o||B.cache!==n.cacheID||wn("ref"))&&(n.cacheID=B.cache,n.v=e());return n.v+n.offset};return r.offset=0,e&&r},Xe={s:Ir,p:"left",p2:"Left",os:"right",os2:"Right",d:"width",d2:"Width",a:"x",sc:an(function(a){return arguments.length?Je.scrollTo(a,pe.sc()):Je.pageXOffset||At[Ir]||Pt[Ir]||lr[Ir]||0})},pe={s:Xr,p:"top",p2:"Top",os:"bottom",os2:"Bottom",d:"height",d2:"Height",a:"y",op:Xe,sc:an(function(a){return arguments.length?Je.scrollTo(Xe.sc(),a):Je.pageYOffset||At[Xr]||Pt[Xr]||lr[Xr]||0})},Ye=function(e,o){return(o&&o._ctx&&o._ctx.selector||ke.utils.toArray)(e)[0]||(typeof e=="string"&&ke.config().nullTargetWarn!==!1?console.warn("Element not found:",e):null)},ri=function(e,o){for(var r=o.length;r--;)if(o[r]===e||o[r].contains(e))return!0;return!1},Wt=function(e,o){var r=o.s,n=o.sc;Er(e)&&(e=At.scrollingElement||Pt);var i=B.indexOf(e),l=n===pe.sc?1:2;!~i&&(i=B.push(e)-1),B[i+l]||We(e,"scroll",kn);var s=B[i+l],d=s||(B[i+l]=an(Ot(e,r),!0)||(Er(e)?n:an(function(h){return arguments.length?e[r]=h:e[r]})));return d.target=e,s||(d.smooth=ke.getProperty(e,"scrollBehavior")==="smooth"),d},jn=function(e,o,r){var n=e,i=e,l=Sr(),s=l,d=o||50,h=Math.max(500,d*3),f=function(y,w){var T=Sr();w||T-l>d?(i=n,n=y,s=l,l=T):r?n+=y:n=i+(y-i)/(T-s)*(l-s)},x=function(){i=n=r?0:n,s=l=0},g=function(y){var w=s,T=i,C=Sr();return(y||y===0)&&y!==n&&f(y),l===s||C-s>h?0:(n+(r?T:-T))/((r?C:l)-w)*1e3};return{update:f,reset:x,getVelocity:g}},yr=function(e,o){return o&&!e._gsapAllow&&e.cancelable!==!1&&e.preventDefault(),e.changedTouches?e.changedTouches[0]:e},qn=function(e){var o=Math.max.apply(Math,e),r=Math.min.apply(Math,e);return Math.abs(o)>=Math.abs(r)?o:r},jo=function(){cr=ke.core.globals().ScrollTrigger,cr&&cr.core&&ti()},_o=function(e){return ke=e||wo(),!Zr&&ke&&typeof document<"u"&&document.body&&(Je=window,At=document,Pt=At.documentElement,lr=At.body,bo=[Je,At,Pt,lr],ke.utils.clamp,vo=ke.core.context||function(){},Yt="onpointerenter"in lr?"pointer":"mouse",yo=re.isTouch=Je.matchMedia&&Je.matchMedia("(hover: none), (pointer: coarse)").matches?1:"ontouchstart"in Je||navigator.maxTouchPoints>0||navigator.msMaxTouchPoints>0?2:0,lt=re.eventTypes=("ontouchstart"in Pt?"touchstart,touchmove,touchcancel,touchend":"onpointerdown"in Pt?"pointerdown,pointermove,pointercancel,pointerup":"mousedown,mousemove,mouseup,mouseup").split(","),setTimeout(function(){return ko=0},500),Zr=1),cr||jo(),Zr};Xe.op=pe;B.cache=0;var re=function(){function a(o){this.init(o)}var e=a.prototype;return e.init=function(r){Zr||_o(ke)||console.warn("Please gsap.registerPlugin(Observer)"),cr||jo();var n=r.tolerance,i=r.dragMinimum,l=r.type,s=r.target,d=r.lineHeight,h=r.debounce,f=r.preventDefault,x=r.onStop,g=r.onStopDelay,c=r.ignore,y=r.wheelSpeed,w=r.event,T=r.onDragStart,C=r.onDragEnd,k=r.onDrag,O=r.onPress,E=r.onRelease,fe=r.onRight,U=r.onLeft,R=r.onUp,be=r.onDown,Fe=r.onChangeX,j=r.onChangeY,me=r.onChange,L=r.onToggleX,ht=r.onToggleY,le=r.onHover,Le=r.onHoverEnd,Ne=r.onMove,$=r.ignoreCheck,ne=r.isNormalizer,oe=r.onGestureStart,u=r.onGestureEnd,ce=r.onWheel,Dt=r.onEnable,St=r.onDisable,Ze=r.onClick,gt=r.scrollSpeed,je=r.capture,ie=r.allowClicks,ze=r.lockAxis,_e=r.onLockAxis;this.target=s=Ye(s)||Pt,this.vars=r,c&&(c=ke.utils.toArray(c)),n=n||1e-9,i=i||0,y=y||1,gt=gt||1,l=l||"wheel,touch,pointer",h=h!==!1,d||(d=parseFloat(Je.getComputedStyle(lr).lineHeight)||22);var Et,Ae,Pe,F,Q,qe,He,p=this,$e=0,xt=0,Rt=r.passive||!f&&r.passive!==!1,J=Wt(s,Xe),yt=Wt(s,pe),Mt=J(),Bt=yt(),he=~l.indexOf("touch")&&!~l.indexOf("pointer")&&lt[0]==="pointerdown",Tt=Er(s),ee=s.ownerDocument||At,nt=[0,0,0],Qe=[0,0,0],bt=0,mr=function(){return bt=Sr()},ae=function(M,q){return(p.event=M)&&c&&ri(M.target,c)||q&&he&&M.pointerType!=="touch"||$&&$(M,q)},Wr=function(){p._vx.reset(),p._vy.reset(),Ae.pause(),x&&x(p)},vt=function(){var M=p.deltaX=qn(nt),q=p.deltaY=qn(Qe),b=Math.abs(M)>=n,N=Math.abs(q)>=n;me&&(b||N)&&me(p,M,q,nt,Qe),b&&(fe&&p.deltaX>0&&fe(p),U&&p.deltaX<0&&U(p),Fe&&Fe(p),L&&p.deltaX<0!=$e<0&&L(p),$e=p.deltaX,nt[0]=nt[1]=nt[2]=0),N&&(be&&p.deltaY>0&&be(p),R&&p.deltaY<0&&R(p),j&&j(p),ht&&p.deltaY<0!=xt<0&&ht(p),xt=p.deltaY,Qe[0]=Qe[1]=Qe[2]=0),(F||Pe)&&(Ne&&Ne(p),Pe&&(T&&Pe===1&&T(p),k&&k(p),Pe=0),F=!1),qe&&!(qe=!1)&&_e&&_e(p),Q&&(ce(p),Q=!1),Et=0},Qt=function(M,q,b){nt[b]+=M,Qe[b]+=q,p._vx.update(M),p._vy.update(q),h?Et||(Et=requestAnimationFrame(vt)):vt()},er=function(M,q){ze&&!He&&(p.axis=He=Math.abs(M)>Math.abs(q)?"x":"y",qe=!0),He!=="y"&&(nt[2]+=M,p._vx.update(M,!0)),He!=="x"&&(Qe[2]+=q,p._vy.update(q,!0)),h?Et||(Et=requestAnimationFrame(vt)):vt()},Lt=function(M){if(!ae(M,1)){M=yr(M,f);var q=M.clientX,b=M.clientY,N=q-p.x,S=b-p.y,z=p.isDragging;p.x=q,p.y=b,(z||(N||S)&&(Math.abs(p.startX-q)>=i||Math.abs(p.startY-b)>=i))&&(Pe||(Pe=z?2:1),z||(p.isDragging=!0),er(N,S))}},It=p.onPress=function(A){ae(A,1)||A&&A.button||(p.axis=He=null,Ae.pause(),p.isPressed=!0,A=yr(A),$e=xt=0,p.startX=p.x=A.clientX,p.startY=p.y=A.clientY,p._vx.reset(),p._vy.reset(),We(ne?s:ee,lt[1],Lt,Rt,!0),p.deltaX=p.deltaY=0,O&&O(p))},I=p.onRelease=function(A){if(!ae(A,1)){Oe(ne?s:ee,lt[1],Lt,!0);var M=!isNaN(p.y-p.startY),q=p.isDragging,b=q&&(Math.abs(p.x-p.startX)>3||Math.abs(p.y-p.startY)>3),N=yr(A);!b&&M&&(p._vx.reset(),p._vy.reset(),f&&ie&&ke.delayedCall(.08,function(){if(Sr()-bt>300&&!A.defaultPrevented){if(A.target.click)A.target.click();else if(ee.createEvent){var S=ee.createEvent("MouseEvents");S.initMouseEvent("click",!0,!0,Je,1,N.screenX,N.screenY,N.clientX,N.clientY,!1,!1,!1,!1,0,null),A.target.dispatchEvent(S)}}})),p.isDragging=p.isGesturing=p.isPressed=!1,x&&q&&!ne&&Ae.restart(!0),Pe&&vt(),C&&q&&C(p),E&&E(p,b)}},Xt=function(M){return M.touches&&M.touches.length>1&&(p.isGesturing=!0)&&oe(M,p.isDragging)},ot=function(){return(p.isGesturing=!1)||u(p)},it=function(M){if(!ae(M)){var q=J(),b=yt();Qt((q-Mt)*gt,(b-Bt)*gt,1),Mt=q,Bt=b,x&&Ae.restart(!0)}},at=function(M){if(!ae(M)){M=yr(M,f),ce&&(Q=!0);var q=(M.deltaMode===1?d:M.deltaMode===2?Je.innerHeight:1)*y;Qt(M.deltaX*q,M.deltaY*q,0),x&&!ne&&Ae.restart(!0)}},Ft=function(M){if(!ae(M)){var q=M.clientX,b=M.clientY,N=q-p.x,S=b-p.y;p.x=q,p.y=b,F=!0,x&&Ae.restart(!0),(N||S)&&er(N,S)}},tr=function(M){p.event=M,le(p)},wt=function(M){p.event=M,Le(p)},hr=function(M){return ae(M)||yr(M,f)&&Ze(p)};Ae=p._dc=ke.delayedCall(g||.25,Wr).pause(),p.deltaX=p.deltaY=0,p._vx=jn(0,50,!0),p._vy=jn(0,50,!0),p.scrollX=J,p.scrollY=yt,p.isDragging=p.isGesturing=p.isPressed=!1,vo(this),p.enable=function(A){return p.isEnabled||(We(Tt?ee:s,"scroll",kn),l.indexOf("scroll")>=0&&We(Tt?ee:s,"scroll",it,Rt,je),l.indexOf("wheel")>=0&&We(s,"wheel",at,Rt,je),(l.indexOf("touch")>=0&&yo||l.indexOf("pointer")>=0)&&(We(s,lt[0],It,Rt,je),We(ee,lt[2],I),We(ee,lt[3],I),ie&&We(s,"click",mr,!0,!0),Ze&&We(s,"click",hr),oe&&We(ee,"gesturestart",Xt),u&&We(ee,"gestureend",ot),le&&We(s,Yt+"enter",tr),Le&&We(s,Yt+"leave",wt),Ne&&We(s,Yt+"move",Ft)),p.isEnabled=!0,p.isDragging=p.isGesturing=p.isPressed=F=Pe=!1,p._vx.reset(),p._vy.reset(),Mt=J(),Bt=yt(),A&&A.type&&It(A),Dt&&Dt(p)),p},p.disable=function(){p.isEnabled&&(sr.filter(function(A){return A!==p&&Er(A.target)}).length||Oe(Tt?ee:s,"scroll",kn),p.isPressed&&(p._vx.reset(),p._vy.reset(),Oe(ne?s:ee,lt[1],Lt,!0)),Oe(Tt?ee:s,"scroll",it,je),Oe(s,"wheel",at,je),Oe(s,lt[0],It,je),Oe(ee,lt[2],I),Oe(ee,lt[3],I),Oe(s,"click",mr,!0),Oe(s,"click",hr),Oe(ee,"gesturestart",Xt),Oe(ee,"gestureend",ot),Oe(s,Yt+"enter",tr),Oe(s,Yt+"leave",wt),Oe(s,Yt+"move",Ft),p.isEnabled=p.isPressed=p.isDragging=!1,St&&St(p))},p.kill=p.revert=function(){p.disable();var A=sr.indexOf(p);A>=0&&sr.splice(A,1),jt===p&&(jt=0)},sr.push(p),ne&&Er(s)&&(jt=p),p.enable(w)},ei(a,[{key:"velocityX",get:function(){return this._vx.getVelocity()}},{key:"velocityY",get:function(){return this._vy.getVelocity()}}]),a}();re.version="3.15.0";re.create=function(a){return new re(a)};re.register=_o;re.getAll=function(){return sr.slice()};re.getById=function(a){return sr.filter(function(e){return e.vars.id===a})[0]};wo()&&ke.registerPlugin(re);/*!
 * ScrollTrigger 3.15.0
 * https://gsap.com
 *
 * @license Copyright 2008-2026, GreenSock. All rights reserved.
 * Subject to the terms at https://gsap.com/standard-license
 * @author: Jack Doyle, jack@greensock.com
*/var v,ir,D,H,Ke,Y,zn,sn,Pr,Rr,wr,Fr,Re,dn,_n,Be,Yn,Hn,ar,Co,pn,So,De,Cn,Eo,Ro,zt,Sn,An,dr,Pn,Mr,En,fn,qr=1,Me=Date.now,mn=Me(),rt=0,kr=0,$n=function(e,o,r){var n=Ve(e)&&(e.substr(0,6)==="clamp("||e.indexOf("max")>-1);return r["_"+o+"Clamp"]=n,n?e.substr(6,e.length-7):e},Gn=function(e,o){return o&&(!Ve(e)||e.substr(0,6)!=="clamp(")?"clamp("+e+")":e},ni=function a(){return kr&&requestAnimationFrame(a)},Un=function(){return dn=1},Vn=function(){return dn=0},pt=function(e){return e},jr=function(e){return Math.round(e*1e5)/1e5||0},Mo=function(){return typeof window<"u"},To=function(){return v||Mo()&&(v=window.gsap)&&v.registerPlugin&&v},Kt=function(e){return!!~zn.indexOf(e)},Lo=function(e){return(e==="Height"?Pn:D["inner"+e])||Ke["client"+e]||Y["client"+e]},No=function(e){return Ot(e,"getBoundingClientRect")||(Kt(e)?function(){return nn.width=D.innerWidth,nn.height=Pn,nn}:function(){return kt(e)})},oi=function(e,o,r){var n=r.d,i=r.d2,l=r.a;return(l=Ot(e,"getBoundingClientRect"))?function(){return l()[n]}:function(){return(o?Lo(i):e["client"+i])||0}},ii=function(e,o){return!o||~mt.indexOf(e)?No(e):function(){return nn}},ft=function(e,o){var r=o.s,n=o.d2,i=o.d,l=o.a;return Math.max(0,(r="scroll"+n)&&(l=Ot(e,r))?l()-No(e)()[i]:Kt(e)?(Ke[r]||Y[r])-Lo(n):e[r]-e["offset"+n])},Yr=function(e,o){for(var r=0;r<ar.length;r+=3)(!o||~o.indexOf(ar[r+1]))&&e(ar[r],ar[r+1],ar[r+2])},Ve=function(e){return typeof e=="string"},Te=function(e){return typeof e=="function"},_r=function(e){return typeof e=="number"},Ht=function(e){return typeof e=="object"},br=function(e,o,r){return e&&e.progress(o?0:1)&&r&&e.pause()},rr=function(e,o,r){if(e.enabled){var n=e._ctx?e._ctx.add(function(){return o(e,r)}):o(e,r);n&&n.totalTime&&(e.callbackAnimation=n)}},nr=Math.abs,zo="left",Ao="top",On="right",Wn="bottom",Gt="width",Ut="height",Tr="Right",Lr="Left",Nr="Top",zr="Bottom",se="padding",et="margin",pr="Width",Dn="Height",ue="px",tt=function(e){return D.getComputedStyle(e.nodeType===Node.DOCUMENT_NODE?e.scrollingElement:e)},ai=function(e){var o=tt(e).position;e.style.position=o==="absolute"||o==="fixed"?o:"relative"},Kn=function(e,o){for(var r in o)r in e||(e[r]=o[r]);return e},kt=function(e,o){var r=o&&tt(e)[_n]!=="matrix(1, 0, 0, 1, 0, 0)"&&v.to(e,{x:0,y:0,xPercent:0,yPercent:0,rotation:0,rotationX:0,rotationY:0,scale:1,skewX:0,skewY:0}).progress(1),n=e.getBoundingClientRect?e.getBoundingClientRect():e.scrollingElement.getBoundingClientRect();return r&&r.progress(0).kill(),n},ln=function(e,o){var r=o.d2;return e["offset"+r]||e["client"+r]||0},Po=function(e){var o=[],r=e.labels,n=e.duration(),i;for(i in r)o.push(r[i]/n);return o},si=function(e){return function(o){return v.utils.snap(Po(e),o)}},Bn=function(e){var o=v.utils.snap(e),r=Array.isArray(e)&&e.slice(0).sort(function(n,i){return n-i});return r?function(n,i,l){l===void 0&&(l=.001);var s;if(!i)return o(n);if(i>0){for(n-=l,s=0;s<r.length;s++)if(r[s]>=n)return r[s];return r[s-1]}else for(s=r.length,n+=l;s--;)if(r[s]<=n)return r[s];return r[0]}:function(n,i,l){l===void 0&&(l=.001);var s=o(n);return!i||Math.abs(s-n)<l||s-n<0==i<0?s:o(i<0?n-e:n+e)}},li=function(e){return function(o,r){return Bn(Po(e))(o,r.direction)}},Hr=function(e,o,r,n){return r.split(",").forEach(function(i){return e(o,i,n)})},ye=function(e,o,r,n,i){return e.addEventListener(o,r,{passive:!n,capture:!!i})},xe=function(e,o,r,n){return e.removeEventListener(o,r,!!n)},$r=function(e,o,r){r=r&&r.wheelHandler,r&&(e(o,"wheel",r),e(o,"touchmove",r))},Jn={startColor:"green",endColor:"red",indent:0,fontSize:"16px",fontWeight:"normal"},Gr={toggleActions:"play",anticipatePin:0},cn={top:0,left:0,center:.5,bottom:1,right:1},Qr=function(e,o){if(Ve(e)){var r=e.indexOf("="),n=~r?+(e.charAt(r-1)+1)*parseFloat(e.substr(r+1)):0;~r&&(e.indexOf("%")>r&&(n*=o/100),e=e.substr(0,r-1)),e=n+(e in cn?cn[e]*o:~e.indexOf("%")?parseFloat(e)*o/100:parseFloat(e)||0)}return e},Ur=function(e,o,r,n,i,l,s,d){var h=i.startColor,f=i.endColor,x=i.fontSize,g=i.indent,c=i.fontWeight,y=H.createElement("div"),w=Kt(r)||Ot(r,"pinType")==="fixed",T=e.indexOf("scroller")!==-1,C=w?Y:r.tagName==="IFRAME"?r.contentDocument.body:r,k=e.indexOf("start")!==-1,O=k?h:f,E="border-color:"+O+";font-size:"+x+";color:"+O+";font-weight:"+c+";pointer-events:none;white-space:nowrap;font-family:sans-serif,Arial;z-index:1000;padding:4px 8px;border-width:0;border-style:solid;";return E+="position:"+((T||d)&&w?"fixed;":"absolute;"),(T||d||!w)&&(E+=(n===pe?On:Wn)+":"+(l+parseFloat(g))+"px;"),s&&(E+="box-sizing:border-box;text-align:left;width:"+s.offsetWidth+"px;"),y._isStart=k,y.setAttribute("class","gsap-marker-"+e+(o?" marker-"+o:"")),y.style.cssText=E,y.innerText=o||o===0?e+"-"+o:e,C.children[0]?C.insertBefore(y,C.children[0]):C.appendChild(y),y._offset=y["offset"+n.op.d2],en(y,0,n,k),y},en=function(e,o,r,n){var i={display:"block"},l=r[n?"os2":"p2"],s=r[n?"p2":"os2"];e._isFlipped=n,i[r.a+"Percent"]=n?-100:0,i[r.a]=n?"1px":0,i["border"+l+pr]=1,i["border"+s+pr]=0,i[r.p]=o+"px",v.set(e,i)},W=[],Rn={},Or,Zn=function(){return Me()-rt>34&&(Or||(Or=requestAnimationFrame(_t)))},or=function(){(!De||!De.isPressed||De.startX>Y.clientWidth)&&(B.cache++,De?Or||(Or=requestAnimationFrame(_t)):_t(),rt||Zt("scrollStart"),rt=Me())},hn=function(){Ro=D.innerWidth,Eo=D.innerHeight},Cr=function(e){B.cache++,(e===!0||!Re&&!So&&!H.fullscreenElement&&!H.webkitFullscreenElement&&(!Cn||Ro!==D.innerWidth||Math.abs(D.innerHeight-Eo)>D.innerHeight*.25))&&sn.restart(!0)},Jt={},ci=[],Oo=function a(){return xe(P,"scrollEnd",a)||$t(!0)},Zt=function(e){return Jt[e]&&Jt[e].map(function(o){return o()})||ci},Ue=[],Wo=function(e){for(var o=0;o<Ue.length;o+=5)(!e||Ue[o+4]&&Ue[o+4].query===e)&&(Ue[o].style.cssText=Ue[o+1],Ue[o].getBBox&&Ue[o].setAttribute("transform",Ue[o+2]||""),Ue[o+3].uncache=1)},Do=function(){return B.forEach(function(e){return Te(e)&&++e.cacheID&&(e.rec=e())})},In=function(e,o){var r;for(Be=0;Be<W.length;Be++)r=W[Be],r&&(!o||r._ctx===o)&&(e?r.kill(1):r.revert(!0,!0));Mr=!0,o&&Wo(o),o||Zt("revert")},Bo=function(e,o){B.cache++,(o||!Ie)&&B.forEach(function(r){return Te(r)&&r.cacheID++&&(r.rec=0)}),Ve(e)&&(D.history.scrollRestoration=An=e)},Ie,Vt=0,Qn,di=function(){if(Qn!==Vt){var e=Qn=Vt;requestAnimationFrame(function(){return e===Vt&&$t(!0)})}},Io=function(){Y.appendChild(dr),Pn=!De&&dr.offsetHeight||D.innerHeight,Y.removeChild(dr)},eo=function(e){return Pr(".gsap-marker-start, .gsap-marker-end, .gsap-marker-scroller-start, .gsap-marker-scroller-end").forEach(function(o){return o.style.display=e?"none":"block"})},$t=function(e,o){if(Ke=H.documentElement,Y=H.body,zn=[D,H,Ke,Y],rt&&!e&&!Mr){ye(P,"scrollEnd",Oo);return}Io(),Ie=P.isRefreshing=!0,Mr||Do();var r=Zt("refreshInit");Co&&P.sort(),o||In(),B.forEach(function(n){Te(n)&&(n.smooth&&(n.target.style.scrollBehavior="auto"),n(0))}),W.slice(0).forEach(function(n){return n.refresh()}),Mr=!1,W.forEach(function(n){if(n._subPinOffset&&n.pin){var i=n.vars.horizontal?"offsetWidth":"offsetHeight",l=n.pin[i];n.revert(!0,1),n.adjustPinSpacing(n.pin[i]-l),n.refresh()}}),En=1,eo(!0),W.forEach(function(n){var i=ft(n.scroller,n._dir),l=n.vars.end==="max"||n._endClamp&&n.end>i,s=n._startClamp&&n.start>=i;(l||s)&&n.setPositions(s?i-1:n.start,l?Math.max(s?i:n.start+1,i):n.end,!0)}),eo(!1),En=0,r.forEach(function(n){return n&&n.render&&n.render(-1)}),B.forEach(function(n){Te(n)&&(n.smooth&&requestAnimationFrame(function(){return n.target.style.scrollBehavior="smooth"}),n.rec&&n(n.rec))}),Bo(An,1),sn.pause(),Vt++,Ie=2,_t(2),W.forEach(function(n){return Te(n.vars.onRefresh)&&n.vars.onRefresh(n)}),Ie=P.isRefreshing=!1,Zt("refresh")},Mn=0,tn=1,Ar,_t=function(e){if(e===2||!Ie&&!Mr){P.isUpdating=!0,Ar&&Ar.update(0);var o=W.length,r=Me(),n=r-mn>=50,i=o&&W[0].scroll();if(tn=Mn>i?-1:1,Ie||(Mn=i),n&&(rt&&!dn&&r-rt>200&&(rt=0,Zt("scrollEnd")),wr=mn,mn=r),tn<0){for(Be=o;Be-- >0;)W[Be]&&W[Be].update(0,n);tn=1}else for(Be=0;Be<o;Be++)W[Be]&&W[Be].update(0,n);P.isUpdating=!1}Or=0},Tn=[zo,Ao,Wn,On,et+zr,et+Tr,et+Nr,et+Lr,"display","flexShrink","float","zIndex","gridColumnStart","gridColumnEnd","gridRowStart","gridRowEnd","gridArea","justifySelf","alignSelf","placeSelf","order"],rn=Tn.concat([Gt,Ut,"boxSizing","max"+pr,"max"+Dn,"position",et,se,se+Nr,se+Tr,se+zr,se+Lr]),ui=function(e,o,r){ur(r);var n=e._gsap;if(n.spacerIsNative)ur(n.spacerState);else if(e._gsap.swappedIn){var i=o.parentNode;i&&(i.insertBefore(e,o),i.removeChild(o))}e._gsap.swappedIn=!1},gn=function(e,o,r,n){if(!e._gsap.swappedIn){for(var i=Tn.length,l=o.style,s=e.style,d;i--;)d=Tn[i],l[d]=r[d];l.position=r.position==="absolute"?"absolute":"relative",r.display==="inline"&&(l.display="inline-block"),s[Wn]=s[On]="auto",l.flexBasis=r.flexBasis||"auto",l.overflow="visible",l.boxSizing="border-box",l[Gt]=ln(e,Xe)+ue,l[Ut]=ln(e,pe)+ue,l[se]=s[et]=s[Ao]=s[zo]="0",ur(n),s[Gt]=s["max"+pr]=r[Gt],s[Ut]=s["max"+Dn]=r[Ut],s[se]=r[se],e.parentNode!==o&&(e.parentNode.insertBefore(o,e),o.appendChild(e)),e._gsap.swappedIn=!0}},pi=/([A-Z])/g,ur=function(e){if(e){var o=e.t.style,r=e.length,n=0,i,l;for((e.t._gsap||v.core.getCache(e.t)).uncache=1;n<r;n+=2)l=e[n+1],i=e[n],l?o[i]=l:o[i]&&o.removeProperty(i.replace(pi,"-$1").toLowerCase())}},Vr=function(e){for(var o=rn.length,r=e.style,n=[],i=0;i<o;i++)n.push(rn[i],r[rn[i]]);return n.t=e,n},fi=function(e,o,r){for(var n=[],i=e.length,l=r?8:0,s;l<i;l+=2)s=e[l],n.push(s,s in o?o[s]:e[l+1]);return n.t=e.t,n},nn={left:0,top:0},to=function(e,o,r,n,i,l,s,d,h,f,x,g,c,y){Te(e)&&(e=e(d)),Ve(e)&&e.substr(0,3)==="max"&&(e=g+(e.charAt(4)==="="?Qr("0"+e.substr(3),r):0));var w=c?c.time():0,T,C,k;if(c&&c.seek(0),isNaN(e)||(e=+e),_r(e))c&&(e=v.utils.mapRange(c.scrollTrigger.start,c.scrollTrigger.end,0,g,e)),s&&en(s,r,n,!0);else{Te(o)&&(o=o(d));var O=(e||"0").split(" "),E,fe,U,R;k=Ye(o,d)||Y,E=kt(k)||{},(!E||!E.left&&!E.top)&&tt(k).display==="none"&&(R=k.style.display,k.style.display="block",E=kt(k),R?k.style.display=R:k.style.removeProperty("display")),fe=Qr(O[0],E[n.d]),U=Qr(O[1]||"0",r),e=E[n.p]-h[n.p]-f+fe+i-U,s&&en(s,U,n,r-U<20||s._isStart&&U>20),r-=r-U}if(y&&(d[y]=e||-.001,e<0&&(e=0)),l){var be=e+r,Fe=l._isStart;T="scroll"+n.d2,en(l,be,n,Fe&&be>20||!Fe&&(x?Math.max(Y[T],Ke[T]):l.parentNode[T])<=be+1),x&&(h=kt(s),x&&(l.style[n.op.p]=h[n.op.p]-n.op.m-l._offset+ue))}return c&&k&&(T=kt(k),c.seek(g),C=kt(k),c._caScrollDist=T[n.p]-C[n.p],e=e/c._caScrollDist*g),c&&c.seek(w),c?e:Math.round(e)},mi=/(webkit|moz|length|cssText|inset)/i,ro=function(e,o,r,n){if(e.parentNode!==o){var i=e.style,l,s;if(o===Y){e._stOrig=i.cssText,s=tt(e);for(l in s)!+l&&!mi.test(l)&&s[l]&&typeof i[l]=="string"&&l!=="0"&&(i[l]=s[l]);i.top=r,i.left=n}else i.cssText=e._stOrig;v.core.getCache(e).uncache=1,o.appendChild(e)}},Xo=function(e,o,r){var n=o,i=n;return function(l){var s=Math.round(e());return s!==n&&s!==i&&Math.abs(s-n)>3&&Math.abs(s-i)>3&&(l=s,r&&r()),i=n,n=Math.round(l),n}},Kr=function(e,o,r){var n={};n[o.p]="+="+r,v.set(e,n)},no=function(e,o){var r=Wt(e,o),n="_scroll"+o.p2,i=function l(s,d,h,f,x){var g=l.tween,c=d.onComplete,y={};h=h||r();var w=Xo(r,h,function(){g.kill(),l.tween=0});return x=f&&x||0,f=f||s-h,g&&g.kill(),d[n]=s,d.inherit=!1,d.modifiers=y,y[n]=function(){return w(h+f*g.ratio+x*g.ratio*g.ratio)},d.onUpdate=function(){B.cache++,l.tween&&_t()},d.onComplete=function(){l.tween=0,c&&c.call(g)},g=l.tween=v.to(e,d),g};return e[n]=r,r.wheelHandler=function(){return i.tween&&i.tween.kill()&&(i.tween=0)},ye(e,"wheel",r.wheelHandler),P.isTouch&&ye(e,"touchmove",r.wheelHandler),i},P=function(){function a(o,r){ir||a.register(v)||console.warn("Please gsap.registerPlugin(ScrollTrigger)"),Sn(this),this.init(o,r)}var e=a.prototype;return e.init=function(r,n){if(this.progress=this.start=0,this.vars&&this.kill(!0,!0),!kr){this.update=this.refresh=this.kill=pt;return}r=Kn(Ve(r)||_r(r)||r.nodeType?{trigger:r}:r,Gr);var i=r,l=i.onUpdate,s=i.toggleClass,d=i.id,h=i.onToggle,f=i.onRefresh,x=i.scrub,g=i.trigger,c=i.pin,y=i.pinSpacing,w=i.invalidateOnRefresh,T=i.anticipatePin,C=i.onScrubComplete,k=i.onSnapComplete,O=i.once,E=i.snap,fe=i.pinReparent,U=i.pinSpacer,R=i.containerAnimation,be=i.fastScrollEnd,Fe=i.preventOverlaps,j=r.horizontal||r.containerAnimation&&r.horizontal!==!1?Xe:pe,me=!x&&x!==0,L=Ye(r.scroller||D),ht=v.core.getCache(L),le=Kt(L),Le=("pinType"in r?r.pinType:Ot(L,"pinType")||le&&"fixed")==="fixed",Ne=[r.onEnter,r.onLeave,r.onEnterBack,r.onLeaveBack],$=me&&r.toggleActions.split(" "),ne="markers"in r?r.markers:Gr.markers,oe=le?0:parseFloat(tt(L)["border"+j.p2+pr])||0,u=this,ce=r.onRefreshInit&&function(){return r.onRefreshInit(u)},Dt=oi(L,le,j),St=ii(L,le),Ze=0,gt=0,je=0,ie=Wt(L,j),ze,_e,Et,Ae,Pe,F,Q,qe,He,p,$e,xt,Rt,J,yt,Mt,Bt,he,Tt,ee,nt,Qe,bt,mr,ae,Wr,vt,Qt,er,Lt,It,I,Xt,ot,it,at,Ft,tr,wt;if(u._startClamp=u._endClamp=!1,u._dir=j,T*=45,u.scroller=L,u.scroll=R?R.time.bind(R):ie,Ae=ie(),u.vars=r,n=n||r.animation,"refreshPriority"in r&&(Co=1,r.refreshPriority===-9999&&(Ar=u)),ht.tweenScroll=ht.tweenScroll||{top:no(L,pe),left:no(L,Xe)},u.tweenTo=ze=ht.tweenScroll[j.p],u.scrubDuration=function(b){Xt=_r(b)&&b,Xt?I?I.duration(b):I=v.to(n,{ease:"expo",totalProgress:"+=0",inherit:!1,duration:Xt,paused:!0,onComplete:function(){return C&&C(u)}}):(I&&I.progress(1).kill(),I=0)},n&&(n.vars.lazy=!1,n._initted&&!u.isReverted||n.vars.immediateRender!==!1&&r.immediateRender!==!1&&n.duration()&&n.render(0,!0,!0),u.animation=n.pause(),n.scrollTrigger=u,u.scrubDuration(x),Lt=0,d||(d=n.vars.id)),E&&((!Ht(E)||E.push)&&(E={snapTo:E}),"scrollBehavior"in Y.style&&v.set(le?[Y,Ke]:L,{scrollBehavior:"auto"}),B.forEach(function(b){return Te(b)&&b.target===(le?H.scrollingElement||Ke:L)&&(b.smooth=!1)}),Et=Te(E.snapTo)?E.snapTo:E.snapTo==="labels"?si(n):E.snapTo==="labelsDirectional"?li(n):E.directional!==!1?function(b,N){return Bn(E.snapTo)(b,Me()-gt<500?0:N.direction)}:v.utils.snap(E.snapTo),ot=E.duration||{min:.1,max:2},ot=Ht(ot)?Rr(ot.min,ot.max):Rr(ot,ot),it=v.delayedCall(E.delay||Xt/2||.1,function(){var b=ie(),N=Me()-gt<500,S=ze.tween;if((N||Math.abs(u.getVelocity())<10)&&!S&&!dn&&Ze!==b){var z=(b-F)/J,ge=n&&!me?n.totalProgress():z,X=N?0:(ge-It)/(Me()-wr)*1e3||0,te=v.utils.clamp(-z,1-z,nr(X/2)*X/.185),Ce=z+(E.inertia===!1?0:te),Z,V,G=E,st=G.onStart,K=G.onInterrupt,Ge=G.onComplete;if(Z=Et(Ce,u),_r(Z)||(Z=Ce),V=Math.max(0,Math.round(F+Z*J)),b<=Q&&b>=F&&V!==b){if(S&&!S._initted&&S.data<=nr(V-b))return;E.inertia===!1&&(te=Z-z),ze(V,{duration:ot(nr(Math.max(nr(Ce-ge),nr(Z-ge))*.185/X/.05||0)),ease:E.ease||"power3",data:nr(V-b),onInterrupt:function(){return it.restart(!0)&&K&&rr(u,K)},onComplete:function(){u.update(),Ze=ie(),n&&!me&&(I?I.resetTo("totalProgress",Z,n._tTime/n._tDur):n.progress(Z)),Lt=It=n&&!me?n.totalProgress():u.progress,k&&k(u),Ge&&rr(u,Ge)}},b,te*J,V-b-te*J),st&&rr(u,st,ze.tween)}}else u.isActive&&Ze!==b&&it.restart(!0)}).pause()),d&&(Rn[d]=u),g=u.trigger=Ye(g||c!==!0&&c),wt=g&&g._gsap&&g._gsap.stRevert,wt&&(wt=wt(u)),c=c===!0?g:Ye(c),Ve(s)&&(s={targets:g,className:s}),c&&(y===!1||y===et||(y=!y&&c.parentNode&&c.parentNode.style&&tt(c.parentNode).display==="flex"?!1:se),u.pin=c,_e=v.core.getCache(c),_e.spacer?yt=_e.pinState:(U&&(U=Ye(U),U&&!U.nodeType&&(U=U.current||U.nativeElement),_e.spacerIsNative=!!U,U&&(_e.spacerState=Vr(U))),_e.spacer=he=U||H.createElement("div"),he.classList.add("pin-spacer"),d&&he.classList.add("pin-spacer-"+d),_e.pinState=yt=Vr(c)),r.force3D!==!1&&v.set(c,{force3D:!0}),u.spacer=he=_e.spacer,er=tt(c),mr=er[y+j.os2],ee=v.getProperty(c),nt=v.quickSetter(c,j.a,ue),gn(c,he,er),Bt=Vr(c)),ne){xt=Ht(ne)?Kn(ne,Jn):Jn,p=Ur("scroller-start",d,L,j,xt,0),$e=Ur("scroller-end",d,L,j,xt,0,p),Tt=p["offset"+j.op.d2];var hr=Ye(Ot(L,"content")||L);qe=this.markerStart=Ur("start",d,hr,j,xt,Tt,0,R),He=this.markerEnd=Ur("end",d,hr,j,xt,Tt,0,R),R&&(tr=v.quickSetter([qe,He],j.a,ue)),!Le&&!(mt.length&&Ot(L,"fixedMarkers")===!0)&&(ai(le?Y:L),v.set([p,$e],{force3D:!0}),Wr=v.quickSetter(p,j.a,ue),Qt=v.quickSetter($e,j.a,ue))}if(R){var A=R.vars.onUpdate,M=R.vars.onUpdateParams;R.eventCallback("onUpdate",function(){u.update(0,0,1),A&&A.apply(R,M||[])})}if(u.previous=function(){return W[W.indexOf(u)-1]},u.next=function(){return W[W.indexOf(u)+1]},u.revert=function(b,N){if(!N)return u.kill(!0);var S=b!==!1||!u.enabled,z=Re;S!==u.isReverted&&(S&&(at=Math.max(ie(),u.scroll.rec||0),je=u.progress,Ft=n&&n.progress()),qe&&[qe,He,p,$e].forEach(function(ge){return ge.style.display=S?"none":"block"}),S&&(Re=u,u.update(S)),c&&(!fe||!u.isActive)&&(S?ui(c,he,yt):gn(c,he,tt(c),ae)),S||u.update(S),Re=z,u.isReverted=S)},u.refresh=function(b,N,S,z){if(!((Re||!u.enabled)&&!N)){if(c&&b&&rt){ye(a,"scrollEnd",Oo);return}!Ie&&ce&&ce(u),Re=u,ze.tween&&!S&&(ze.tween.kill(),ze.tween=0),I&&I.pause(),w&&n&&(n.revert({kill:!1}).invalidate(),n.getChildren?n.getChildren(!0,!0,!1).forEach(function(Nt){return Nt.vars.immediateRender&&Nt.render(0,!0,!0)}):n.vars.immediateRender&&n.render(0,!0,!0)),u.isReverted||u.revert(!0,!0),u._subPinOffset=!1;var ge=Dt(),X=St(),te=R?R.duration():ft(L,j),Ce=J<=.01||!J,Z=0,V=z||0,G=Ht(S)?S.end:r.end,st=r.endTrigger||g,K=Ht(S)?S.start:r.start||(r.start===0||!g?0:c?"0 0":"0 100%"),Ge=u.pinnedContainer=r.pinnedContainer&&Ye(r.pinnedContainer,u),ct=g&&Math.max(0,W.indexOf(u))||0,ve=ct,we,Se,qt,Dr,Ee,de,dt,un,Fn,gr,ut,xr,Br;for(ne&&Ht(S)&&(xr=v.getProperty(p,j.p),Br=v.getProperty($e,j.p));ve-- >0;)de=W[ve],de.end||de.refresh(0,1)||(Re=u),dt=de.pin,dt&&(dt===g||dt===c||dt===Ge)&&!de.isReverted&&(gr||(gr=[]),gr.unshift(de),de.revert(!0,!0)),de!==W[ve]&&(ct--,ve--);for(Te(K)&&(K=K(u)),K=$n(K,"start",u),F=to(K,g,ge,j,ie(),qe,p,u,X,oe,Le,te,R,u._startClamp&&"_startClamp")||(c?-.001:0),Te(G)&&(G=G(u)),Ve(G)&&!G.indexOf("+=")&&(~G.indexOf(" ")?G=(Ve(K)?K.split(" ")[0]:"")+G:(Z=Qr(G.substr(2),ge),G=Ve(K)?K:(R?v.utils.mapRange(0,R.duration(),R.scrollTrigger.start,R.scrollTrigger.end,F):F)+Z,st=g)),G=$n(G,"end",u),Q=Math.max(F,to(G||(st?"100% 0":te),st,ge,j,ie()+Z,He,$e,u,X,oe,Le,te,R,u._endClamp&&"_endClamp"))||-.001,Z=0,ve=ct;ve--;)de=W[ve]||{},dt=de.pin,dt&&de.start-de._pinPush<=F&&!R&&de.end>0&&(we=de.end-(u._startClamp?Math.max(0,de.start):de.start),(dt===g&&de.start-de._pinPush<F||dt===Ge)&&isNaN(K)&&(Z+=we*(1-de.progress)),dt===c&&(V+=we));if(F+=Z,Q+=Z,u._startClamp&&(u._startClamp+=Z),u._endClamp&&!Ie&&(u._endClamp=Q||-.001,Q=Math.min(Q,ft(L,j))),J=Q-F||(F-=.01)&&.001,Ce&&(je=v.utils.clamp(0,1,v.utils.normalize(F,Q,at))),u._pinPush=V,qe&&Z&&(we={},we[j.a]="+="+Z,Ge&&(we[j.p]="-="+ie()),v.set([qe,He],we)),c&&!(En&&u.end>=ft(L,j)))we=tt(c),Dr=j===pe,qt=ie(),Qe=parseFloat(ee(j.a))+V,!te&&Q>1&&(ut=(le?H.scrollingElement||Ke:L).style,ut={style:ut,value:ut["overflow"+j.a.toUpperCase()]},le&&tt(Y)["overflow"+j.a.toUpperCase()]!=="scroll"&&(ut.style["overflow"+j.a.toUpperCase()]="scroll")),gn(c,he,we),Bt=Vr(c),Se=kt(c,!0),un=Le&&Wt(L,Dr?Xe:pe)(),y?(ae=[y+j.os2,J+V+ue],ae.t=he,ve=y===se?ln(c,j)+J+V:0,ve&&(ae.push(j.d,ve+ue),he.style.flexBasis!=="auto"&&(he.style.flexBasis=ve+ue)),ur(ae),Ge&&W.forEach(function(Nt){Nt.pin===Ge&&Nt.vars.pinSpacing!==!1&&(Nt._subPinOffset=!0)}),Le&&ie(at)):(ve=ln(c,j),ve&&he.style.flexBasis!=="auto"&&(he.style.flexBasis=ve+ue)),Le&&(Ee={top:Se.top+(Dr?qt-F:un)+ue,left:Se.left+(Dr?un:qt-F)+ue,boxSizing:"border-box",position:"fixed"},Ee[Gt]=Ee["max"+pr]=Math.ceil(Se.width)+ue,Ee[Ut]=Ee["max"+Dn]=Math.ceil(Se.height)+ue,Ee[et]=Ee[et+Nr]=Ee[et+Tr]=Ee[et+zr]=Ee[et+Lr]="0",Ee[se]=we[se],Ee[se+Nr]=we[se+Nr],Ee[se+Tr]=we[se+Tr],Ee[se+zr]=we[se+zr],Ee[se+Lr]=we[se+Lr],Mt=fi(yt,Ee,fe),Ie&&ie(0)),n?(Fn=n._initted,pn(1),n.render(n.duration(),!0,!0),bt=ee(j.a)-Qe+J+V,vt=Math.abs(J-bt)>1,Le&&vt&&Mt.splice(Mt.length-2,2),n.render(0,!0,!0),Fn||n.invalidate(!0),n.parent||n.totalTime(n.totalTime()),pn(0)):bt=J,ut&&(ut.value?ut.style["overflow"+j.a.toUpperCase()]=ut.value:ut.style.removeProperty("overflow-"+j.a));else if(g&&ie()&&!R)for(Se=g.parentNode;Se&&Se!==Y;)Se._pinOffset&&(F-=Se._pinOffset,Q-=Se._pinOffset),Se=Se.parentNode;gr&&gr.forEach(function(Nt){return Nt.revert(!1,!0)}),u.start=F,u.end=Q,Ae=Pe=Ie?at:ie(),!R&&!Ie&&(Ae<at&&ie(at),u.scroll.rec=0),u.revert(!1,!0),gt=Me(),it&&(Ze=-1,it.restart(!0)),Re=0,n&&me&&(n._initted||Ft)&&n.progress()!==Ft&&n.progress(Ft||0,!0).render(n.time(),!0,!0),(Ce||je!==u.progress||R||w||n&&!n._initted)&&(n&&!me&&(n._initted||je||n.vars.immediateRender!==!1)&&n.totalProgress(R&&F<-.001&&!je?v.utils.normalize(F,Q,0):je,!0),u.progress=Ce||(Ae-F)/J===je?0:je),c&&y&&(he._pinOffset=Math.round(u.progress*bt)),I&&I.invalidate(),isNaN(xr)||(xr-=v.getProperty(p,j.p),Br-=v.getProperty($e,j.p),Kr(p,j,xr),Kr(qe,j,xr-(z||0)),Kr($e,j,Br),Kr(He,j,Br-(z||0))),Ce&&!Ie&&u.update(),f&&!Ie&&!Rt&&(Rt=!0,f(u),Rt=!1)}},u.getVelocity=function(){return(ie()-Pe)/(Me()-wr)*1e3||0},u.endAnimation=function(){br(u.callbackAnimation),n&&(I?I.progress(1):n.paused()?me||br(n,u.direction<0,1):br(n,n.reversed()))},u.labelToScroll=function(b){return n&&n.labels&&(F||u.refresh()||F)+n.labels[b]/n.duration()*J||0},u.getTrailing=function(b){var N=W.indexOf(u),S=u.direction>0?W.slice(0,N).reverse():W.slice(N+1);return(Ve(b)?S.filter(function(z){return z.vars.preventOverlaps===b}):S).filter(function(z){return u.direction>0?z.end<=F:z.start>=Q})},u.update=function(b,N,S){if(!(R&&!S&&!b)){var z=Ie===!0?at:u.scroll(),ge=b?0:(z-F)/J,X=ge<0?0:ge>1?1:ge||0,te=u.progress,Ce,Z,V,G,st,K,Ge,ct;if(N&&(Pe=Ae,Ae=R?ie():z,E&&(It=Lt,Lt=n&&!me?n.totalProgress():X)),T&&c&&!Re&&!qr&&rt&&(!X&&F<z+(z-Pe)/(Me()-wr)*T?X=1e-4:X===1&&Q>z+(z-Pe)/(Me()-wr)*T&&(X=.9999)),X!==te&&u.enabled){if(Ce=u.isActive=!!X&&X<1,Z=!!te&&te<1,K=Ce!==Z,st=K||!!X!=!!te,u.direction=X>te?1:-1,u.progress=X,st&&!Re&&(V=X&&!te?0:X===1?1:te===1?2:3,me&&(G=!K&&$[V+1]!=="none"&&$[V+1]||$[V],ct=n&&(G==="complete"||G==="reset"||G in n))),Fe&&(K||ct)&&(ct||x||!n)&&(Te(Fe)?Fe(u):u.getTrailing(Fe).forEach(function(qt){return qt.endAnimation()})),me||(I&&!Re&&!qr?(I._dp._time-I._start!==I._time&&I.render(I._dp._time-I._start),I.resetTo?I.resetTo("totalProgress",X,n._tTime/n._tDur):(I.vars.totalProgress=X,I.invalidate().restart())):n&&n.totalProgress(X,!!(Re&&(gt||b)))),c){if(b&&y&&(he.style[y+j.os2]=mr),!Le)nt(jr(Qe+bt*X));else if(st){if(Ge=!b&&X>te&&Q+1>z&&z+1>=ft(L,j),fe)if(!b&&(Ce||Ge)){var ve=kt(c,!0),we=z-F;ro(c,Y,ve.top+(j===pe?we:0)+ue,ve.left+(j===pe?0:we)+ue)}else ro(c,he);ur(Ce||Ge?Mt:Bt),vt&&X<1&&Ce||nt(Qe+(X===1&&!Ge?bt:0))}}E&&!ze.tween&&!Re&&!qr&&it.restart(!0),s&&(K||O&&X&&(X<1||!fn))&&Pr(s.targets).forEach(function(qt){return qt.classList[Ce||O?"add":"remove"](s.className)}),l&&!me&&!b&&l(u),st&&!Re?(me&&(ct&&(G==="complete"?n.pause().totalProgress(1):G==="reset"?n.restart(!0).pause():G==="restart"?n.restart(!0):n[G]()),l&&l(u)),(K||!fn)&&(h&&K&&rr(u,h),Ne[V]&&rr(u,Ne[V]),O&&(X===1?u.kill(!1,1):Ne[V]=0),K||(V=X===1?1:3,Ne[V]&&rr(u,Ne[V]))),be&&!Ce&&Math.abs(u.getVelocity())>(_r(be)?be:2500)&&(br(u.callbackAnimation),I?I.progress(1):br(n,G==="reverse"?1:!X,1))):me&&l&&!Re&&l(u)}if(Qt){var Se=R?z/R.duration()*(R._caScrollDist||0):z;Wr(Se+(p._isFlipped?1:0)),Qt(Se)}tr&&tr(-z/R.duration()*(R._caScrollDist||0))}},u.enable=function(b,N){u.enabled||(u.enabled=!0,ye(L,"resize",Cr),le||ye(L,"scroll",or),ce&&ye(a,"refreshInit",ce),b!==!1&&(u.progress=je=0,Ae=Pe=Ze=ie()),N!==!1&&u.refresh())},u.getTween=function(b){return b&&ze?ze.tween:I},u.setPositions=function(b,N,S,z){if(R){var ge=R.scrollTrigger,X=R.duration(),te=ge.end-ge.start;b=ge.start+te*b/X,N=ge.start+te*N/X}u.refresh(!1,!1,{start:Gn(b,S&&!!u._startClamp),end:Gn(N,S&&!!u._endClamp)},z),u.update()},u.adjustPinSpacing=function(b){if(ae&&b){var N=ae.indexOf(j.d)+1;ae[N]=parseFloat(ae[N])+b+ue,ae[1]=parseFloat(ae[1])+b+ue,ur(ae)}},u.disable=function(b,N){if(b!==!1&&u.revert(!0,!0),u.enabled&&(u.enabled=u.isActive=!1,N||I&&I.pause(),at=0,_e&&(_e.uncache=1),ce&&xe(a,"refreshInit",ce),it&&(it.pause(),ze.tween&&ze.tween.kill()&&(ze.tween=0)),!le)){for(var S=W.length;S--;)if(W[S].scroller===L&&W[S]!==u)return;xe(L,"resize",Cr),le||xe(L,"scroll",or)}},u.kill=function(b,N){u.disable(b,N),I&&!N&&I.kill(),d&&delete Rn[d];var S=W.indexOf(u);S>=0&&W.splice(S,1),S===Be&&tn>0&&Be--,S=0,W.forEach(function(z){return z.scroller===u.scroller&&(S=1)}),S||Ie||(u.scroll.rec=0),n&&(n.scrollTrigger=null,b&&n.revert({kill:!1}),N||n.kill()),qe&&[qe,He,p,$e].forEach(function(z){return z.parentNode&&z.parentNode.removeChild(z)}),Ar===u&&(Ar=0),c&&(_e&&(_e.uncache=1),S=0,W.forEach(function(z){return z.pin===c&&S++}),S||(_e.spacer=0)),r.onKill&&r.onKill(u)},W.push(u),u.enable(!1,!1),wt&&wt(u),n&&n.add&&!J){var q=u.update;u.update=function(){u.update=q,B.cache++,F||Q||u.refresh()},v.delayedCall(.01,u.update),J=.01,F=Q=0}else u.refresh();c&&di()},a.register=function(r){return ir||(v=r||To(),Mo()&&window.document&&a.enable(),ir=kr),ir},a.defaults=function(r){if(r)for(var n in r)Gr[n]=r[n];return Gr},a.disable=function(r,n){kr=0,W.forEach(function(l){return l[n?"kill":"disable"](r)}),xe(D,"wheel",or),xe(H,"scroll",or),clearInterval(Fr),xe(H,"touchcancel",pt),xe(Y,"touchstart",pt),Hr(xe,H,"pointerdown,touchstart,mousedown",Un),Hr(xe,H,"pointerup,touchend,mouseup",Vn),sn.kill(),Yr(xe);for(var i=0;i<B.length;i+=3)$r(xe,B[i],B[i+1]),$r(xe,B[i],B[i+2])},a.enable=function(){if(D=window,H=document,Ke=H.documentElement,Y=H.body,v){if(Pr=v.utils.toArray,Rr=v.utils.clamp,Sn=v.core.context||pt,pn=v.core.suppressOverwrites||pt,An=D.history.scrollRestoration||"auto",Mn=D.pageYOffset||0,v.core.globals("ScrollTrigger",a),Y){kr=1,dr=document.createElement("div"),dr.style.height="100vh",dr.style.position="absolute",Io(),ni(),re.register(v),a.isTouch=re.isTouch,zt=re.isTouch&&/(iPad|iPhone|iPod|Mac)/g.test(navigator.userAgent),Cn=re.isTouch===1,ye(D,"wheel",or),zn=[D,H,Ke,Y],v.matchMedia?(a.matchMedia=function(f){var x=v.matchMedia(),g;for(g in f)x.add(g,f[g]);return x},v.addEventListener("matchMediaInit",function(){Do(),In()}),v.addEventListener("matchMediaRevert",function(){return Wo()}),v.addEventListener("matchMedia",function(){$t(0,1),Zt("matchMedia")}),v.matchMedia().add("(orientation: portrait)",function(){return hn(),hn})):console.warn("Requires GSAP 3.11.0 or later"),hn(),ye(H,"scroll",or);var r=Y.hasAttribute("style"),n=Y.style,i=n.borderTopStyle,l=v.core.Animation.prototype,s,d;for(l.revert||Object.defineProperty(l,"revert",{value:function(){return this.time(-.01,!0)}}),n.borderTopStyle="solid",s=kt(Y),pe.m=Math.round(s.top+pe.sc())||0,Xe.m=Math.round(s.left+Xe.sc())||0,i?n.borderTopStyle=i:n.removeProperty("border-top-style"),r||(Y.setAttribute("style",""),Y.removeAttribute("style")),Fr=setInterval(Zn,250),v.delayedCall(.5,function(){return qr=0}),ye(H,"touchcancel",pt),ye(Y,"touchstart",pt),Hr(ye,H,"pointerdown,touchstart,mousedown",Un),Hr(ye,H,"pointerup,touchend,mouseup",Vn),_n=v.utils.checkPrefix("transform"),rn.push(_n),ir=Me(),sn=v.delayedCall(.2,$t).pause(),ar=[H,"visibilitychange",function(){var f=D.innerWidth,x=D.innerHeight;H.hidden?(Yn=f,Hn=x):(Yn!==f||Hn!==x)&&Cr()},H,"DOMContentLoaded",$t,D,"load",$t,D,"resize",Cr],Yr(ye),W.forEach(function(f){return f.enable(0,1)}),d=0;d<B.length;d+=3)$r(xe,B[d],B[d+1]),$r(xe,B[d],B[d+2])}else if(H){var h=function f(){a.enable(),H.removeEventListener("DOMContentLoaded",f)};H.addEventListener("DOMContentLoaded",h)}}},a.config=function(r){"limitCallbacks"in r&&(fn=!!r.limitCallbacks);var n=r.syncInterval;n&&clearInterval(Fr)||(Fr=n)&&setInterval(Zn,n),"ignoreMobileResize"in r&&(Cn=a.isTouch===1&&r.ignoreMobileResize),"autoRefreshEvents"in r&&(Yr(xe)||Yr(ye,r.autoRefreshEvents||"none"),So=(r.autoRefreshEvents+"").indexOf("resize")===-1)},a.scrollerProxy=function(r,n){var i=Ye(r),l=B.indexOf(i),s=Kt(i);~l&&B.splice(l,s?6:2),n&&(s?mt.unshift(D,n,Y,n,Ke,n):mt.unshift(i,n))},a.clearMatchMedia=function(r){W.forEach(function(n){return n._ctx&&n._ctx.query===r&&n._ctx.kill(!0,!0)})},a.isInViewport=function(r,n,i){var l=(Ve(r)?Ye(r):r).getBoundingClientRect(),s=l[i?Gt:Ut]*n||0;return i?l.right-s>0&&l.left+s<D.innerWidth:l.bottom-s>0&&l.top+s<D.innerHeight},a.positionInViewport=function(r,n,i){Ve(r)&&(r=Ye(r));var l=r.getBoundingClientRect(),s=l[i?Gt:Ut],d=n==null?s/2:n in cn?cn[n]*s:~n.indexOf("%")?parseFloat(n)*s/100:parseFloat(n)||0;return i?(l.left+d)/D.innerWidth:(l.top+d)/D.innerHeight},a.killAll=function(r){if(W.slice(0).forEach(function(i){return i.vars.id!=="ScrollSmoother"&&i.kill()}),r!==!0){var n=Jt.killAll||[];Jt={},n.forEach(function(i){return i()})}},a}();P.version="3.15.0";P.saveStyles=function(a){return a?Pr(a).forEach(function(e){if(e&&e.style){var o=Ue.indexOf(e);o>=0&&Ue.splice(o,5),Ue.push(e,e.style.cssText,e.getBBox&&e.getAttribute("transform"),v.core.getCache(e),Sn())}}):Ue};P.revert=function(a,e){return In(!a,e)};P.create=function(a,e){return new P(a,e)};P.refresh=function(a){return a?Cr(!0):(ir||P.register())&&$t(!0)};P.update=function(a){return++B.cache&&_t(a===!0?2:0)};P.clearScrollMemory=Bo;P.maxScroll=function(a,e){return ft(a,e?Xe:pe)};P.getScrollFunc=function(a,e){return Wt(Ye(a),e?Xe:pe)};P.getById=function(a){return Rn[a]};P.getAll=function(){return W.filter(function(a){return a.vars.id!=="ScrollSmoother"})};P.isScrolling=function(){return!!rt};P.snapDirectional=Bn;P.addEventListener=function(a,e){var o=Jt[a]||(Jt[a]=[]);~o.indexOf(e)||o.push(e)};P.removeEventListener=function(a,e){var o=Jt[a],r=o&&o.indexOf(e);r>=0&&o.splice(r,1)};P.batch=function(a,e){var o=[],r={},n=e.interval||.016,i=e.batchMax||1e9,l=function(h,f){var x=[],g=[],c=v.delayedCall(n,function(){f(x,g),x=[],g=[]}).pause();return function(y){x.length||c.restart(!0),x.push(y.trigger),g.push(y),i<=x.length&&c.progress(1)}},s;for(s in e)r[s]=s.substr(0,2)==="on"&&Te(e[s])&&s!=="onRefreshInit"?l(s,e[s]):e[s];return Te(i)&&(i=i(),ye(P,"refresh",function(){return i=e.batchMax()})),Pr(a).forEach(function(d){var h={};for(s in r)h[s]=r[s];h.trigger=d,o.push(P.create(h))}),o};var oo=function(e,o,r,n){return o>n?e(n):o<0&&e(0),r>n?(n-o)/(r-o):r<0?o/(o-r):1},xn=function a(e,o){o===!0?e.style.removeProperty("touch-action"):e.style.touchAction=o===!0?"auto":o?"pan-"+o+(re.isTouch?" pinch-zoom":""):"none",e===Ke&&a(Y,o)},Jr={auto:1,scroll:1},hi=function(e){var o=e.event,r=e.target,n=e.axis,i=(o.changedTouches?o.changedTouches[0]:o).target,l=i._gsap||v.core.getCache(i),s=Me(),d;if(!l._isScrollT||s-l._isScrollT>2e3){for(;i&&i!==Y&&(i.scrollHeight<=i.clientHeight&&i.scrollWidth<=i.clientWidth||!(Jr[(d=tt(i)).overflowY]||Jr[d.overflowX]));)i=i.parentNode;l._isScroll=i&&i!==r&&!Kt(i)&&(Jr[(d=tt(i)).overflowY]||Jr[d.overflowX]),l._isScrollT=s}(l._isScroll||n==="x")&&(o.stopPropagation(),o._gsapAllow=!0)},Fo=function(e,o,r,n){return re.create({target:e,capture:!0,debounce:!1,lockAxis:!0,type:o,onWheel:n=n&&hi,onPress:n,onDrag:n,onScroll:n,onEnable:function(){return r&&ye(H,re.eventTypes[0],ao,!1,!0)},onDisable:function(){return xe(H,re.eventTypes[0],ao,!0)}})},gi=/(input|label|select|textarea)/i,io,ao=function(e){var o=gi.test(e.target.tagName);(o||io)&&(e._gsapAllow=!0,io=o)},xi=function(e){Ht(e)||(e={}),e.preventDefault=e.isNormalizer=e.allowClicks=!0,e.type||(e.type="wheel,touch"),e.debounce=!!e.debounce,e.id=e.id||"normalizer";var o=e,r=o.normalizeScrollX,n=o.momentum,i=o.allowNestedScroll,l=o.onRelease,s,d,h=Ye(e.target)||Ke,f=v.core.globals().ScrollSmoother,x=f&&f.get(),g=zt&&(e.content&&Ye(e.content)||x&&e.content!==!1&&!x.smooth()&&x.content()),c=Wt(h,pe),y=Wt(h,Xe),w=1,T=(re.isTouch&&D.visualViewport?D.visualViewport.scale*D.visualViewport.width:D.outerWidth)/D.innerWidth,C=0,k=Te(n)?function(){return n(s)}:function(){return n||2.8},O,E,fe=Fo(h,e.type,!0,i),U=function(){return E=!1},R=pt,be=pt,Fe=function(){d=ft(h,pe),be=Rr(zt?1:0,d),r&&(R=Rr(0,ft(h,Xe))),O=Vt},j=function(){g._gsap.y=jr(parseFloat(g._gsap.y)+c.offset)+"px",g.style.transform="matrix3d(1, 0, 0, 0, 0, 1, 0, 0, 0, 0, 1, 0, 0, "+parseFloat(g._gsap.y)+", 0, 1)",c.offset=c.cacheID=0},me=function(){if(E){requestAnimationFrame(U);var ne=jr(s.deltaY/2),oe=be(c.v-ne);if(g&&oe!==c.v+c.offset){c.offset=oe-c.v;var u=jr((parseFloat(g&&g._gsap.y)||0)-c.offset);g.style.transform="matrix3d(1, 0, 0, 0, 0, 1, 0, 0, 0, 0, 1, 0, 0, "+u+", 0, 1)",g._gsap.y=u+"px",c.cacheID=B.cache,_t()}return!0}c.offset&&j(),E=!0},L,ht,le,Le,Ne=function(){Fe(),L.isActive()&&L.vars.scrollY>d&&(c()>d?L.progress(1)&&c(d):L.resetTo("scrollY",d))};return g&&v.set(g,{y:"+=0"}),e.ignoreCheck=function($){return zt&&$.type==="touchmove"&&me()||w>1.05&&$.type!=="touchstart"||s.isGesturing||$.touches&&$.touches.length>1},e.onPress=function(){E=!1;var $=w;w=jr((D.visualViewport&&D.visualViewport.scale||1)/T),L.pause(),$!==w&&xn(h,w>1.01?!0:r?!1:"x"),ht=y(),le=c(),Fe(),O=Vt},e.onRelease=e.onGestureStart=function($,ne){if(c.offset&&j(),!ne)Le.restart(!0);else{B.cache++;var oe=k(),u,ce;r&&(u=y(),ce=u+oe*.05*-$.velocityX/.227,oe*=oo(y,u,ce,ft(h,Xe)),L.vars.scrollX=R(ce)),u=c(),ce=u+oe*.05*-$.velocityY/.227,oe*=oo(c,u,ce,ft(h,pe)),L.vars.scrollY=be(ce),L.invalidate().duration(oe).play(.01),(zt&&L.vars.scrollY>=d||u>=d-1)&&v.to({},{onUpdate:Ne,duration:oe})}l&&l($)},e.onWheel=function(){L._ts&&L.pause(),Me()-C>1e3&&(O=0,C=Me())},e.onChange=function($,ne,oe,u,ce){if(Vt!==O&&Fe(),ne&&r&&y(R(u[2]===ne?ht+($.startX-$.x):y()+ne-u[1])),oe){c.offset&&j();var Dt=ce[2]===oe,St=Dt?le+$.startY-$.y:c()+oe-ce[1],Ze=be(St);Dt&&St!==Ze&&(le+=Ze-St),c(Ze)}(oe||ne)&&_t()},e.onEnable=function(){xn(h,r?!1:"x"),P.addEventListener("refresh",Ne),ye(D,"resize",Ne),c.smooth&&(c.target.style.scrollBehavior="auto",c.smooth=y.smooth=!1),fe.enable()},e.onDisable=function(){xn(h,!0),xe(D,"resize",Ne),P.removeEventListener("refresh",Ne),fe.kill()},e.lockAxis=e.lockAxis!==!1,s=new re(e),s.iOS=zt,zt&&!c()&&c(1),zt&&v.ticker.add(pt),Le=s._dc,L=v.to(s,{ease:"power4",paused:!0,inherit:!1,scrollX:r?"+=0.1":"+=0",scrollY:"+=0.1",modifiers:{scrollY:Xo(c,c(),function(){return L.pause()})},onUpdate:_t,onComplete:Le.vars.onComplete}),s};P.sort=function(a){if(Te(a))return W.sort(a);var e=D.pageYOffset||0;return P.getAll().forEach(function(o){return o._sortY=o.trigger?e+o.trigger.getBoundingClientRect().top:o.start+D.innerHeight}),W.sort(a||function(o,r){return(o.vars.refreshPriority||0)*-1e6+(o.vars.containerAnimation?1e6:o._sortY)-((r.vars.containerAnimation?1e6:r._sortY)+(r.vars.refreshPriority||0)*-1e6)})};P.observe=function(a){return new re(a)};P.normalizeScroll=function(a){if(typeof a>"u")return De;if(a===!0&&De)return De.enable();if(a===!1){De&&De.kill(),De=a;return}var e=a instanceof re?a:xi(a);return De&&De.target===e.target&&De.kill(),Kt(e.target)&&(De=e),e};P.core={_getVelocityProp:jn,_inputObserver:Fo,_scrollers:B,_proxies:mt,bridge:{ss:function(){rt||Zt("scrollStart"),rt=Me()},ref:function(){return Re}}};To()&&v.registerPlugin(P);function yi(){return t.jsxs("nav",{className:"nx-navbar","aria-label":"Navegación principal",children:[t.jsx("span",{className:"nx-navbar__logo",children:"NEXO"}),t.jsxs("ul",{className:"nx-navbar__links",children:[t.jsx("li",{children:t.jsx("a",{href:"#como-funciona",children:"Cómo funciona"})}),t.jsx("li",{children:t.jsx("a",{href:"#el-nodo",children:"El nodo"})}),t.jsx("li",{children:t.jsx("a",{href:"#roles",children:"Roles"})}),t.jsx("li",{children:t.jsx("a",{href:"#seguridad",children:"Seguridad"})}),t.jsx("li",{children:t.jsx("a",{href:"#contacto",children:"Contacto"})})]})]})}const bi="/assets/models/nodonuevo.glb";function vi({type:a,scale:e=1,showShield:o=!1,scrollProgress:r,isUserDragging:n,dragDeltaRef:i,dragSensitivity:l=.008,isMobile:s=!1}){const{gl:d}=Ho(),{scene:h}=mo(bi),f=m.useRef(),x=m.useRef(),g=m.useRef(),c=m.useRef(!1),y=m.useMemo(()=>{if(!h)return null;const w=h.clone(),T=d.capabilities.getMaxAnisotropy();return w.traverse(C=>{C.isMesh&&(C.castShadow=!0,C.receiveShadow=!0,C.material&&(Array.isArray(C.material)?C.material:[C.material]).forEach(O=>{O.map&&(O.map.anisotropy=T)}))}),w},[h,d]);return m.useEffect(()=>{if(!y||!f.current||!x.current||c.current)return;c.current=!0;const w=new $o().setFromObject(y),T=new on,C=new on;w.getSize(T),w.getCenter(C);const k=Math.max(T.x,T.y,T.z);if(k>0){const E=2.6*e/k;f.current.scale.setScalar(E),x.current.position.set(-C.x,-C.y,-C.z),setTimeout(()=>P.refresh(),100)}},[y,e]),Nn((w,T)=>{if(!x.current)return;let C=!1;if(n&&i?.current){const{dx:k,dy:O}=i.current;(Math.abs(k)>.001||Math.abs(O)>.001)&&(x.current.rotation.y+=k*l,x.current.rotation.x+=O*l,x.current.rotation.x=Math.max(-Math.PI/4,Math.min(Math.PI/4,x.current.rotation.x)),i.current={dx:0,dy:0}),C=!0}else x.current.rotation.y+=.004,C=!0;if(g.current?.material){const k=w.clock.getElapsedTime(),O=g.current.material;O.distort=Uo.lerp(O.distort,.05,.08),g.current.position.y=.1+Math.sin(k*.3)*.004,g.current.rotation.y-=T*.04,C=!0}C&&w.invalidate()}),t.jsxs("group",{ref:f,position:[0,0,0],children:[t.jsx("group",{ref:x,children:y&&t.jsx("primitive",{object:y})}),o&&t.jsxs("mesh",{ref:g,scale:[1.15,1.15,1.15],position:[0,.1,0],children:[t.jsx("sphereGeometry",{args:[1.3,32,32]}),t.jsx(Go,{attach:"material",color:"#2d6e30",distort:.05,speed:.4,roughness:.25,metalness:.9,transparent:!0,opacity:.45,wireframe:!0})]})]})}mo.preload("/assets/models/nodonuevo.glb");function yn(a){const e=document.createElement("canvas");e.width=256,e.height=256;const o=e.getContext("2d");o.imageSmoothingEnabled=!0,o.clearRect(0,0,256,256),o.strokeStyle="rgba(45, 110, 48, 0.45)",o.lineWidth=3,o.setLineDash([8,12]),o.beginPath(),o.arc(128,128,122,0,Math.PI*2),o.stroke(),o.setLineDash([]);const r=o.createRadialGradient(128,128,60,128,128,116);r.addColorStop(0,"#56b85a"),r.addColorStop(1,"#2d6e30"),o.fillStyle=r,o.beginPath(),o.arc(128,128,114,0,Math.PI*2),o.fill(),o.strokeStyle="rgba(255, 255, 255, 0.85)",o.lineWidth=5,o.beginPath(),o.arc(128,128,110,0,Math.PI*2),o.stroke(),o.fillStyle="#ffffff";const n=(l,s,d)=>{o.beginPath(),o.arc(l,s-18*d,22*d,0,Math.PI*2),o.fill(),o.beginPath(),o.arc(l,s+35*d,40*d,Math.PI,Math.PI*2),o.fill()};a==="single"?n(128,128,1.25):a==="group"?(n(98,138,.95),n(158,124,.95)):a==="group3"&&(n(85,142,.8),n(171,142,.8),n(128,116,.8));const i=new ho(e);return i.colorSpace=go,i.needsUpdate=!0,i}function wi(){const a=document.createElement("canvas");a.width=64,a.height=64;const e=a.getContext("2d");e.clearRect(0,0,64,64);const o=e.createRadialGradient(32,32,2,32,32,30);o.addColorStop(0,"#56b85a"),o.addColorStop(.3,"rgba(45, 110, 48, 0.8)"),o.addColorStop(1,"rgba(45, 110, 48, 0)"),e.fillStyle=o,e.beginPath(),e.arc(32,32,30,0,Math.PI*2),e.fill();const r=new ho(a);return r.colorSpace=go,r.needsUpdate=!0,r}const vr=[{pos:[-3,1.8,-.4],size:.52,type:"group"},{pos:[1.8,1.2,.5],size:.82,type:"single"},{pos:[-1.2,-.6,.3],size:.65,type:"group3"},{pos:[2.8,-1.6,-.2],size:.7,type:"group"},{pos:[-3.4,-1.4,-.1],size:.45,type:"single"},{pos:[-.2,2,.2],size:.55,type:"single"},{pos:[-2,.8,-.8],size:.16,type:"dot"},{pos:[.6,2.4,-.4],size:.18,type:"dot"},{pos:[-.6,.6,.8],size:.14,type:"dot"},{pos:[3.2,.4,-.6],size:.15,type:"dot"},{pos:[.1,-1.6,.3],size:.16,type:"dot"},{pos:[1.1,-.4,-.5],size:.15,type:"dot"},{pos:[-2.4,-2.4,.4],size:.13,type:"dot"},{pos:[3.8,-.8,.2],size:.14,type:"dot"},{pos:[-1.8,-1.8,-.6],size:.15,type:"dot"},{pos:[.2,-.2,-1.2],size:.13,type:"dot"}];function ki({count:a=80}){const e=m.useRef(),[o,r]=m.useMemo(()=>{const n=[],i=[];for(let l=0;l<a;l++)n.push((Math.random()-.5)*11,(Math.random()-.5)*7,(Math.random()-.5)*4),i.push((Math.random()-.5)*.05,(Math.random()-.5)*.05,(Math.random()-.5)*.05);return[new Float32Array(n),new Float32Array(i)]},[a]);return Nn((n,i)=>{if(!e.current)return;const l=e.current.geometry.attributes.position;for(let s=0;s<a;s++){const d=s*3;l.array[d]+=r[d]*i*4,l.array[d+1]+=r[d+1]*i*4,l.array[d+2]+=r[d+2]*i*4,Math.abs(l.array[d])>5.5&&(l.array[d]*=-.95),Math.abs(l.array[d+1])>3.5&&(l.array[d+1]*=-.95),Math.abs(l.array[d+2])>2&&(l.array[d+2]*=-.95)}l.needsUpdate=!0}),t.jsxs("points",{ref:e,children:[t.jsx("bufferGeometry",{children:t.jsx("bufferAttribute",{attach:"attributes-position",args:[o,3]})}),t.jsx("pointsMaterial",{color:"#56b85a",size:.06,transparent:!0,opacity:.65})]})}function ji({onHoverChange:a}){const e=m.useRef(),o=m.useMemo(()=>({single:yn("single"),group:yn("group"),group3:yn("group3"),dot:wi()}),[]),r=m.useMemo(()=>{const l=[];for(let s=0;s<vr.length;s++)for(let d=s+1;d<vr.length;d++){const h=vr[s].pos,f=vr[d].pos;Math.sqrt((h[0]-f[0])**2+(h[1]-f[1])**2+(h[2]-f[2])**2)<3.8&&(l.push(new on(...h)),l.push(new on(...f)))}return new Jo().setFromPoints(l)},[]);Nn(l=>{if(e.current){const s=l.clock.getElapsedTime();e.current.rotation.y=Math.sin(s*.15)*.2,e.current.rotation.x=Math.cos(s*.1)*.1,e.current.position.y=Math.sin(s*.3)*.08}});const n=l=>{l.stopPropagation(),a&&a(!0)},i=l=>{l.stopPropagation(),a&&a(!1)};return t.jsxs("group",{ref:e,onPointerOver:n,onPointerOut:i,children:[t.jsx(ki,{count:90}),t.jsx("lineSegments",{geometry:r,children:t.jsx("lineBasicMaterial",{color:"#2d6e30",transparent:!0,opacity:.25,linewidth:1})}),vr.map((l,s)=>{const d=o[l.type]||o.dot;return t.jsx("sprite",{position:l.pos,scale:[l.size*2,l.size*2,1],children:t.jsx("spriteMaterial",{map:d,transparent:!0})},s)})]})}function _i({onDrag:a,onDragStart:e,onDragEnd:o}){const r=m.useRef();m.useEffect(()=>{const i=r.current;if(!i)return;let l=!1,s={x:0,y:0},d=null;const h=(k,O)=>{l=!0,s={x:k,y:O},i.style.cursor="grabbing",clearTimeout(d),e&&e()},f=(k,O)=>{if(!l)return;const E=k-s.x,fe=O-s.y;s={x:k,y:O},a&&a(E,fe)},x=()=>{l&&(l=!1,i.style.cursor="grab",d=setTimeout(()=>{o&&o()},2e3))},g=k=>h(k.clientX,k.clientY),c=k=>f(k.clientX,k.clientY),y=()=>x(),w=k=>h(k.touches[0].clientX,k.touches[0].clientY),T=k=>{if(!l)return;const O=k.touches[0].clientX-s.x,E=k.touches[0].clientY-s.y;if(Math.abs(E)>Math.abs(O)*1.5&&Math.abs(O)<8){x();return}k.preventDefault(),f(k.touches[0].clientX,k.touches[0].clientY)},C=()=>x();return i.addEventListener("mousedown",g),window.addEventListener("mousemove",c),window.addEventListener("mouseup",y),i.addEventListener("touchstart",w,{passive:!0}),i.addEventListener("touchmove",T,{passive:!1}),i.addEventListener("touchend",C,{passive:!0}),()=>{clearTimeout(d),i.removeEventListener("mousedown",g),window.removeEventListener("mousemove",c),window.removeEventListener("mouseup",y),i.removeEventListener("touchstart",w),i.removeEventListener("touchmove",T),i.removeEventListener("touchend",C)}},[a,e,o]);const n=typeof window<"u"&&window.innerWidth<=768;return t.jsx("div",{ref:r,style:{position:"absolute",inset:0,zIndex:10,cursor:"grab",touchAction:n?"pan-y":"none"}})}function Ln({type:a,scale:e=1,showShield:o=!1,coldLight:r=!1,interactive:n=!0,scrollProgress:i}){const[l,s]=m.useState(!1),[d,h]=m.useState(!1),f=m.useRef({dx:0,dy:0}),x=m.useRef(null);m.useEffect(()=>{const R=()=>{h(window.innerWidth<=768)};return R(),window.addEventListener("resize",R),()=>window.removeEventListener("resize",R)},[]);const g=m.useCallback((R,be)=>{f.current={dx:R,dy:be},s(!0)},[]),c=m.useCallback(()=>{s(!0)},[]),y=m.useCallback(()=>{s(!1),f.current={dx:0,dy:0}},[]),w="#ffffff",T=r?2.5:2.2,C="#c8e6c8",k=r?1.2:.8,O="#2d6e30",E=r?1.8:1.5,fe=e,U={position:"relative",width:"100%",height:"100%"};return t.jsxs("div",{style:U,children:[t.jsxs(Vo,{camera:{position:[0,0,a==="grid"?9:6],fov:45},gl:{antialias:!0,alpha:!0,powerPreference:"high-performance",precision:d?"mediump":"highp"},dpr:[1,Math.min(window.devicePixelRatio,2)],shadows:!d,frameloop:d?"always":"demand",style:{width:"100%",height:"100%",pointerEvents:"none"},children:[t.jsx("ambientLight",{intensity:d?.6:.3}),t.jsx("directionalLight",{position:[5,5,5],intensity:T,color:w,castShadow:!d}),t.jsx("directionalLight",{position:[-5,-2,3],intensity:k,color:C}),t.jsx("directionalLight",{position:[-3,5,-5],intensity:E,color:O}),!d&&t.jsx(Ko,{preset:"city"}),t.jsx(m.Suspense,{fallback:null,children:a==="grid"?t.jsx(ji,{}):t.jsx(vi,{type:a,scale:fe,showShield:o,scrollProgress:i,isUserDragging:l,dragDeltaRef:f,modelRef:x,dragSensitivity:d?.015:.008,isMobile:d})})]}),a!=="grid"&&t.jsx(_i,{onDrag:g,onDragStart:c,onDragEnd:y}),a!=="grid"&&d&&t.jsx("div",{"aria-hidden":"true",className:"nx-touch-rotate-hint",style:{position:"absolute",bottom:"0.85rem",left:"50%",transform:"translateX(-50%)",fontSize:"0.6rem",fontWeight:600,letterSpacing:"0.12em",textTransform:"uppercase",color:"rgba(74, 110, 76, 0.7)",whiteSpace:"nowrap",pointerEvents:"none",zIndex:20},children:"← Desliza para rotar →"})]})}const Ci=[{value:"",label:"Selecciona tu cargo"},{value:"rector",label:"Rector"},{value:"coordinador",label:"Coordinador"},{value:"docente",label:"Docente"},{value:"secretaria",label:"Secretaría de Educación"},{value:"otro",label:"Otro"}];function Si(a){const e={};a.nombre.trim()||(e.nombre="El nombre es obligatorio."),a.cargo||(e.cargo="Selecciona tu cargo."),a.institucion.trim()||(e.institucion="El nombre de la institución es obligatorio."),a.municipio.trim()||(e.municipio="El municipio y departamento son obligatorios.");const o=/^[^\s@]+@[^\s@]+\.[^\s@]+$/;a.email.trim()?o.test(a.email)||(e.email="Ingresa un correo electrónico válido."):e.email="El correo es obligatorio.";const r=a.whatsapp.replace(/\D/g,"");return a.whatsapp.trim()?r.length<10&&(e.whatsapp="El WhatsApp debe tener mínimo 10 dígitos."):e.whatsapp="El WhatsApp es obligatorio.",e}const Ei={nombre:"",cargo:"",institucion:"",municipio:"",email:"",whatsapp:"",mensaje:""};function qo({onClose:a}){const e=m.useRef(),o=m.useRef(),[r,n]=m.useState(Ei),[i,l]=m.useState({}),[s,d]=m.useState("idle");m.useEffect(()=>{const c=_.context(()=>{_.fromTo(e.current,{opacity:0},{opacity:1,duration:.25,ease:"power2.out"}),_.fromTo(o.current,{scale:.92,opacity:0,y:24},{scale:1,opacity:1,y:0,duration:.35,ease:"power3.out",delay:.05})});return()=>c.revert()},[]);const h=()=>{_.to(o.current,{scale:.94,opacity:0,y:16,duration:.22,ease:"power2.in"}),_.to(e.current,{opacity:0,duration:.28,ease:"power2.in",onComplete:a})};m.useEffect(()=>{const c=y=>{y.key==="Escape"&&h()};return window.addEventListener("keydown",c),()=>window.removeEventListener("keydown",c)},[]),m.useEffect(()=>(document.body.style.overflow="hidden",()=>{document.body.style.overflow=""}),[]);const f=c=>y=>n(w=>({...w,[c]:y.target.value})),x=async c=>{c.preventDefault();const y=Si(r);l(y),!(Object.keys(y).length>0)&&(d("loading"),console.log("[NEXO] Solicitud de contacto:",JSON.stringify(r,null,2)),await new Promise(w=>setTimeout(w,1200)),d("success"))},g=c=>({width:"100%",background:"var(--nx-void)",border:`1px solid ${i[c]?"rgba(255,80,80,0.6)":"var(--nx-border)"}`,borderRadius:"0.625rem",padding:"0.75rem 1rem",fontSize:"0.875rem",color:"var(--nx-white)",outline:"none",fontFamily:"inherit",transition:"border-color 0.2s",boxSizing:"border-box"});return t.jsxs("div",{ref:e,onClick:h,style:{position:"fixed",inset:0,zIndex:1e4,background:"rgba(0,0,0,0.75)",backdropFilter:"blur(12px)",WebkitBackdropFilter:"blur(12px)",display:"flex",alignItems:"center",justifyContent:"center",padding:"1.5rem"},"aria-modal":"true",role:"dialog","aria-label":"Formulario de contacto NEXO",children:[t.jsxs("div",{ref:o,className:"nx-modal-panel",onClick:c=>c.stopPropagation(),style:{background:"var(--nx-deep)",border:"1px solid var(--nx-border)",borderRadius:"1.25rem",width:"100%",maxWidth:"560px",maxHeight:"90vh",overflowY:"auto",padding:"2.5rem",position:"relative",willChange:"transform, opacity"},children:[t.jsx("button",{onClick:h,"aria-label":"Cerrar",style:{position:"absolute",top:"1.25rem",right:"1.25rem",background:"none",border:"1px solid var(--nx-border)",borderRadius:"50%",width:"32px",height:"32px",cursor:"pointer",display:"flex",alignItems:"center",justifyContent:"center",color:"var(--nx-muted)",transition:"border-color 0.2s, color 0.2s"},onMouseEnter:c=>{c.currentTarget.style.borderColor="var(--nx-text)",c.currentTarget.style.color="var(--nx-white)"},onMouseLeave:c=>{c.currentTarget.style.borderColor="var(--nx-border)",c.currentTarget.style.color="var(--nx-muted)"},children:t.jsx("svg",{width:"14",height:"14",viewBox:"0 0 14 14",fill:"none",stroke:"currentColor",strokeWidth:"1.5",strokeLinecap:"round",children:t.jsx("path",{d:"M2 2l10 10M12 2L2 12"})})}),s==="success"?t.jsxs("div",{style:{textAlign:"center",padding:"2rem 0"},children:[t.jsxs("svg",{width:"56",height:"56",viewBox:"0 0 56 56",fill:"none",style:{margin:"0 auto 1.5rem",display:"block",animation:"successPop 0.5s cubic-bezier(0.175,0.885,0.32,1.275)"},children:[t.jsx("circle",{cx:"28",cy:"28",r:"26",stroke:"var(--nx-green)",strokeWidth:"1.5"}),t.jsx("path",{d:"M18 28l7 7 14-14",stroke:"var(--nx-green)",strokeWidth:"2",strokeLinecap:"round",strokeLinejoin:"round"})]}),t.jsx("h3",{style:{fontSize:"1.1rem",fontWeight:800,color:"var(--nx-white)",marginBottom:"0.75rem"},children:"Tu solicitud fue recibida."}),t.jsx("p",{style:{fontSize:"0.875rem",color:"var(--nx-muted)",lineHeight:1.6},children:"Nos comunicaremos contigo en menos de 24 horas."})]}):t.jsxs(t.Fragment,{children:[t.jsxs("div",{style:{marginBottom:"2rem"},children:[t.jsx("div",{className:"nx-eyebrow",style:{marginBottom:"0.75rem"},children:"Contacto"}),t.jsx("h2",{style:{fontSize:"1.25rem",fontWeight:800,color:"var(--nx-white)",lineHeight:1.2},children:"Quiero que NEXO llegue a mi institución"})]}),t.jsxs("form",{onSubmit:x,noValidate:!0,style:{display:"flex",flexDirection:"column",gap:"1.1rem"},children:[t.jsxs("div",{children:[t.jsx("label",{style:{display:"block",fontSize:"0.78rem",fontWeight:600,color:"var(--nx-text)",marginBottom:"0.4rem"},children:"Nombre completo *"}),t.jsx("input",{type:"text",value:r.nombre,onChange:f("nombre"),placeholder:"Tu nombre completo",style:g("nombre"),onFocus:c=>c.target.style.borderColor="rgba(45, 110, 48, 0.5)",onBlur:c=>c.target.style.borderColor=i.nombre?"rgba(255,80,80,0.6)":"var(--nx-border)"}),i.nombre&&t.jsx("p",{style:{fontSize:"0.72rem",color:"#ff7070",marginTop:"0.3rem"},children:i.nombre})]}),t.jsxs("div",{children:[t.jsx("label",{style:{display:"block",fontSize:"0.78rem",fontWeight:600,color:"var(--nx-text)",marginBottom:"0.4rem"},children:"Cargo *"}),t.jsx("select",{value:r.cargo,onChange:f("cargo"),style:{...g("cargo"),appearance:"none",cursor:"pointer"},children:Ci.map(c=>t.jsx("option",{value:c.value,style:{background:"#f7fcf7"},children:c.label},c.value))}),i.cargo&&t.jsx("p",{style:{fontSize:"0.72rem",color:"#ff7070",marginTop:"0.3rem"},children:i.cargo})]}),t.jsxs("div",{children:[t.jsx("label",{style:{display:"block",fontSize:"0.78rem",fontWeight:600,color:"var(--nx-text)",marginBottom:"0.4rem"},children:"Nombre de la institución *"}),t.jsx("input",{type:"text",value:r.institucion,onChange:f("institucion"),placeholder:"I.E. San Carlos, Colegio...",style:g("institucion"),onFocus:c=>c.target.style.borderColor="rgba(45, 110, 48, 0.5)",onBlur:c=>c.target.style.borderColor=i.institucion?"rgba(255,80,80,0.6)":"var(--nx-border)"}),i.institucion&&t.jsx("p",{style:{fontSize:"0.72rem",color:"#ff7070",marginTop:"0.3rem"},children:i.institucion})]}),t.jsxs("div",{children:[t.jsx("label",{style:{display:"block",fontSize:"0.78rem",fontWeight:600,color:"var(--nx-text)",marginBottom:"0.4rem"},children:"Municipio y departamento *"}),t.jsx("input",{type:"text",value:r.municipio,onChange:f("municipio"),placeholder:"Medellín, Antioquia",style:g("municipio"),onFocus:c=>c.target.style.borderColor="rgba(45, 110, 48, 0.5)",onBlur:c=>c.target.style.borderColor=i.municipio?"rgba(255,80,80,0.6)":"var(--nx-border)"}),i.municipio&&t.jsx("p",{style:{fontSize:"0.72rem",color:"#ff7070",marginTop:"0.3rem"},children:i.municipio})]}),t.jsxs("div",{children:[t.jsx("label",{style:{display:"block",fontSize:"0.78rem",fontWeight:600,color:"var(--nx-text)",marginBottom:"0.4rem"},children:"Correo electrónico institucional *"}),t.jsx("input",{type:"email",value:r.email,onChange:f("email"),placeholder:"nombre@institución.edu.co",style:g("email"),onFocus:c=>c.target.style.borderColor="rgba(45, 110, 48, 0.5)",onBlur:c=>c.target.style.borderColor=i.email?"rgba(255,80,80,0.6)":"var(--nx-border)"}),i.email&&t.jsx("p",{style:{fontSize:"0.72rem",color:"#ff7070",marginTop:"0.3rem"},children:i.email})]}),t.jsxs("div",{children:[t.jsx("label",{style:{display:"block",fontSize:"0.78rem",fontWeight:600,color:"var(--nx-text)",marginBottom:"0.4rem"},children:"WhatsApp de contacto *"}),t.jsx("input",{type:"tel",value:r.whatsapp,onChange:f("whatsapp"),placeholder:"+57 310 000 0000",style:g("whatsapp"),onFocus:c=>c.target.style.borderColor="rgba(45, 110, 48, 0.5)",onBlur:c=>c.target.style.borderColor=i.whatsapp?"rgba(255,80,80,0.6)":"var(--nx-border)"}),i.whatsapp&&t.jsx("p",{style:{fontSize:"0.72rem",color:"#ff7070",marginTop:"0.3rem"},children:i.whatsapp})]}),t.jsxs("div",{children:[t.jsxs("label",{style:{display:"block",fontSize:"0.78rem",fontWeight:600,color:"var(--nx-text)",marginBottom:"0.4rem"},children:["¿Algo que quieras contarnos?",t.jsx("span",{style:{fontWeight:400,color:"var(--nx-muted)",marginLeft:"0.4rem"},children:"(opcional)"})]}),t.jsx("textarea",{value:r.mensaje,onChange:c=>{c.target.value.length<=300&&f("mensaje")(c)},placeholder:"Cuéntanos el contexto de tu institución...",rows:3,style:{...g("mensaje"),resize:"vertical",minHeight:"80px"},onFocus:c=>c.target.style.borderColor="rgba(45, 110, 48, 0.5)",onBlur:c=>c.target.style.borderColor="var(--nx-border)"}),t.jsxs("p",{style:{fontSize:"0.68rem",color:"var(--nx-muted-2)",marginTop:"0.25rem",textAlign:"right"},children:[r.mensaje.length,"/300"]})]}),t.jsx("button",{type:"submit",disabled:s==="loading",className:"nx-btn-primary",style:{width:"100%",padding:"0.9rem",fontSize:"0.9rem",marginTop:"0.5rem",opacity:s==="loading"?.75:1,cursor:s==="loading"?"not-allowed":"pointer",display:"flex",alignItems:"center",justifyContent:"center",gap:"0.6rem"},children:s==="loading"?t.jsxs(t.Fragment,{children:[t.jsxs("svg",{width:"16",height:"16",viewBox:"0 0 16 16",fill:"none",style:{animation:"spinnerRot 0.8s linear infinite"},children:[t.jsx("circle",{cx:"8",cy:"8",r:"6",stroke:"rgba(255,255,255,0.25)",strokeWidth:"2"}),t.jsx("path",{d:"M8 2a6 6 0 016 6",stroke:"white",strokeWidth:"2",strokeLinecap:"round"})]}),"Enviando..."]}):"Enviar solicitud"}),t.jsx("p",{style:{fontSize:"0.7rem",color:"var(--nx-muted-2)",textAlign:"center"},children:"Tus datos son tratados conforme a la Ley 1581 de protección de datos personales."})]})]})]}),t.jsx("style",{children:`
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
      `})]})}function Ct(a,e,{isFirst:o=!1,isLast:r=!1}={}){m.useEffect(()=>{const n=a.current,i=e.current;if(!n||!i)return;if(window.innerWidth<=768){if(_.set(i,{clearProps:"all"}),!o){_.set(i,{opacity:0,y:24});const d=new IntersectionObserver(([h])=>{h.isIntersecting&&(_.to(i,{opacity:1,y:0,duration:.55,ease:"power2.out"}),d.disconnect())},{threshold:.08});return d.observe(i),()=>d.disconnect()}_.set(i,{opacity:1,y:0});return}const s=_.context(()=>{o?_.set(i,{yPercent:0,opacity:1,scale:1}):_.fromTo(i,{yPercent:6,opacity:0,scale:.98},{yPercent:0,opacity:1,scale:1,ease:"none",scrollTrigger:{trigger:n,start:"top 90%",end:"top 20%",scrub:.8}}),r||_.fromTo(i,{yPercent:0,opacity:1,scale:1},{yPercent:-6,opacity:0,scale:.98,ease:"none",scrollTrigger:{trigger:n,start:"bottom 30%",end:"bottom top",scrub:.8}})});return()=>s.revert()},[a,e,o,r])}function Ri(){const a=m.useRef(),e=m.useRef(),o=m.useRef(),r=m.useRef(),n=m.useRef(),i=m.useRef(),l=m.useRef(),s=m.useRef(),d=m.useRef(),[h,f]=m.useState(!1),[x,g]=m.useState(!1);return m.useEffect(()=>{const c=()=>g(window.innerWidth<=768);return c(),window.addEventListener("resize",c),()=>window.removeEventListener("resize",c)},[]),Ct(a,e,{isFirst:!0}),m.useEffect(()=>{const c=window.innerWidth<=768;if(!a.current)return;const w=_.timeline({delay:c?.2:.1});return w.fromTo(o.current,{opacity:0,y:c?-8:-12},{opacity:1,y:0,duration:.6,ease:"power3.out"}).fromTo([r.current,n.current],{opacity:0,y:c?30:60},{opacity:1,y:0,duration:c?.7:1,ease:"expo.out",stagger:.12},"-=0.35").fromTo(i.current,{opacity:0,y:c?15:24},{opacity:1,y:0,duration:.75,ease:"power3.out"},"-=0.5").fromTo(l.current?.children||[],{opacity:0,y:12},{opacity:1,y:0,duration:.65,ease:"power3.out",stagger:.1},"-=0.4").fromTo(s.current,{opacity:0},{opacity:1,duration:.5,ease:"power2.out"},"-=0.2").fromTo(d.current,{opacity:0,scale:c?.98:.96},{opacity:1,scale:1,duration:c?1:1.5,ease:"expo.out"},.3),()=>w.kill()},[]),t.jsxs(t.Fragment,{children:[h&&t.jsx(qo,{onClose:()=>f(!1)}),t.jsx("div",{ref:a,className:"section-wrapper",id:"hero",children:t.jsxs("section",{ref:e,className:"section-inner",style:{background:"var(--nx-hero-gradient)",position:"relative",overflow:x?"visible":"hidden",paddingLeft:"var(--nx-section-px)",paddingRight:"var(--nx-section-px)",display:"flex",alignItems:"center"},children:[t.jsx("div",{"aria-hidden":"true",className:"nx-hero-glow-ambient",style:{position:"absolute",right:"5%",top:"50%",transform:"translateY(-50%)",width:"580px",height:"580px",background:"radial-gradient(circle, rgba(45, 110, 48, 0.12) 0%, transparent 65%)",borderRadius:"50%",pointerEvents:"none",zIndex:0}}),t.jsxs("div",{className:"nx-hero-grid",style:{position:"relative",zIndex:1,display:"grid",gridTemplateColumns:"1fr 1fr",gap:"3rem",alignItems:"center",maxWidth:"1280px",margin:"0 auto",width:"100%"},children:[t.jsxs("div",{className:"nx-hero-copy",children:[t.jsx("div",{ref:o,className:"nx-eyebrow",style:{opacity:0},children:"Sistema de Custodia Educativa en Tiempo Real — Colombia"}),t.jsxs("h1",{className:"nx-h1",style:{marginBottom:"1.75rem",overflow:"visible"},children:[t.jsx("span",{ref:r,style:{display:"block",opacity:0,shortcut:"none",willChange:"transform, opacity"},children:"La presencia estudiantil"}),t.jsx("span",{ref:n,style:{display:"block",opacity:0,shortcut:"none",willChange:"transform, opacity"},children:"ya no puede ser un punto ciego."})]}),t.jsx("p",{ref:i,className:"nx-body",style:{maxWidth:"500px",marginBottom:"2.5rem",opacity:0},children:"NEXO cierra ese vacío. Control de presencia, trazabilidad completa, comunicación institucional y procesos automatizados con análisis inteligente en tiempo real. En una infraestructura que opera con conexión autónoma y batería de respaldo ante cortes de luz."}),x&&t.jsx("div",{ref:d,className:"nx-hero-canvas nx-hero-canvas--inline","aria-label":"Modelo 3D del nodo NEXO",style:{height:"340px",borderRadius:"1rem",overflow:"hidden",position:"relative",marginBottom:"1.5rem",width:"100%"},children:t.jsx(Ln,{type:"solo",scale:1.1,coldLight:!0})}),t.jsxs("div",{ref:l,style:{display:"flex",alignItems:"center",gap:"1.25rem",flexWrap:"wrap",marginBottom:"1.25rem"},children:[t.jsx("button",{id:"hero-cta-primary",className:"nx-btn-primary",onClick:()=>f(!0),type:"button",style:{opacity:0},children:"Quiero que NEXO llegue a mi institución"}),t.jsxs("a",{href:"#como-funciona",className:"nx-link-arrow",id:"hero-cta-how",style:{opacity:0},children:["¿Eres rector o directivo? Ve cómo funciona",t.jsx("svg",{width:"14",height:"14",viewBox:"0 0 14 14",fill:"none","aria-hidden":!0,children:t.jsx("path",{d:"M2 7h10M8 3l4 4-4 4",stroke:"currentColor",strokeWidth:"1.5",strokeLinecap:"round",strokeLinejoin:"round"})})]})]}),t.jsx("p",{ref:s,className:"nx-micro",style:{opacity:0},children:"NEXO desea amparar la necesidad de corresponsabilidad familia-escuela, alerta temprana y trazabilidad de eventos en el sistema educativo colombiano."})]}),!x&&t.jsxs("div",{ref:d,className:"nx-hero-canvas","aria-label":"Modelo 3D del nodo NEXO",style:{height:"520px",borderRadius:"1.5rem",overflow:"hidden",position:"relative",opacity:0,willChange:"transform, opacity"},children:[t.jsx(Ln,{type:"solo",scale:1.1,coldLight:!0}),t.jsx("div",{"aria-hidden":"true",style:{position:"absolute",bottom:"1.25rem",left:"50%",transform:"translateX(-50%)",fontSize:"0.65rem",fontWeight:600,letterSpacing:"0.14em",textTransform:"uppercase",color:"var(--nx-muted-2)",whiteSpace:"nowrap"},children:"Nodo NEXO — Hardware biométrico"})]})]}),t.jsxs("div",{"aria-hidden":"true",className:"nx-hero-scroll-indicator",style:{position:"absolute",bottom:"2.5rem",left:"50%",transform:"translateX(-50%)",display:"flex",flexDirection:"column",alignItems:"center",gap:"0.5rem",opacity:0,animation:"heroScrollIn 0.6s ease 2s forwards"},children:[t.jsx("span",{className:"nx-micro",children:"Desliza"}),t.jsx("div",{style:{width:"1px",height:"48px",background:"linear-gradient(to bottom, rgba(107,127,163,0.6), transparent)",animation:"scrollBlink 2.2s ease-in-out infinite"}})]}),t.jsx("style",{children:`
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
                height: 340px !important;
                border-radius: 1rem !important;
                overflow: hidden !important;
                margin-bottom: 1.5rem !important;
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
          `})]})})]})}function fr(a,e={}){m.useEffect(()=>{if(!a.current)return;const o=a.current.querySelectorAll(".nx-reveal");if(!o.length)return;const r=new IntersectionObserver(n=>{n.forEach(i=>{i.isIntersecting&&i.target.classList.add("is-visible")})},{threshold:.15,rootMargin:"0px 0px -40px 0px",...e});return o.forEach(n=>r.observe(n)),()=>r.disconnect()},[])}const Mi=[{id:"lista",icon:t.jsxs("svg",{width:"28",height:"28",viewBox:"0 0 28 28",fill:"none",stroke:"currentColor",strokeWidth:"1.25",strokeLinecap:"round",strokeLinejoin:"round","aria-hidden":!0,children:[t.jsx("rect",{x:"4",y:"3",width:"20",height:"22",rx:"2"}),t.jsx("line",{x1:"9",y1:"9",x2:"19",y2:"9"}),t.jsx("line",{x1:"9",y1:"14",x2:"19",y2:"14"}),t.jsx("line",{x1:"9",y1:"19",x2:"15",y2:"19"})]}),title:"El registro manual de asistencia",body:"En la mayoría de las instituciones educativas colombianas, el registro de asistencia consume tiempo de clase que los docentes no pueden recuperar. Ese tiempo existe, se acumula día tras día, y es irrecuperable. No es tiempo administrativo: es tiempo de cátedra que los estudiantes no reciben."},{id:"salida",icon:t.jsxs("svg",{width:"28",height:"28",viewBox:"0 0 28 28",fill:"none",stroke:"currentColor",strokeWidth:"1.25",strokeLinecap:"round",strokeLinejoin:"round","aria-hidden":!0,children:[t.jsx("path",{d:"M18 14H4M4 14l4-4M4 14l4 4"}),t.jsx("path",{d:"M12 5h9a2 2 0 012 2v14a2 2 0 01-2 2h-9"})]}),title:"Los estudiantes que nadie ve salir",body:"Entre el cambio de una clase y la siguiente, entre una salida al baño y el regreso, hay intervalos donde las instituciones pierden trazabilidad sobre sus estudiantes. Cuando ocurre un incidente en ese margen invisible, la responsabilidad institucional queda expuesta sin capacidad de acción."},{id:"padre",icon:t.jsxs("svg",{width:"28",height:"28",viewBox:"0 0 28 28",fill:"none",stroke:"currentColor",strokeWidth:"1.25",strokeLinecap:"round",strokeLinejoin:"round","aria-hidden":!0,children:[t.jsx("path",{d:"M20 4H8a2 2 0 00-2 2v14a2 2 0 002 2h12a2 2 0 002-2V6a2 2 0 00-2-2z"}),t.jsx("line",{x1:"14",y1:"10",x2:"14",y2:"16"}),t.jsx("circle",{cx:"14",cy:"19",r:"0.5",fill:"currentColor"})]}),title:"Las familias fuera del circuito",body:"Las inasistencias registradas en papel o en sistemas desconectados llegan a los acudientes con retrasos de días — o no llegan. Las familias no forman parte del circuito de información en tiempo real, lo que genera brechas de comunicación que ninguna institución puede permitirse cuando está en cuestión el cuidado y la custodia de un menor."}];function Ti(){const a=m.useRef(),e=m.useRef(),o=m.useRef(),r=m.useRef([]),n=m.useRef();return fr(e),Ct(a,e),m.useEffect(()=>{const i=a.current;if(!i)return;if(window.innerWidth<=768){_.set([r.current,n.current],{opacity:1,y:0}),_.set(o.current?.querySelectorAll("span")||[],{opacity:1,y:0});return}const s=o.current;if(s){const h=s.textContent.trim().split(/\s+/);s.innerHTML=h.map(f=>`<span style="display:inline-block;opacity:0;transform:translateY(40px)">${f}</span>`).join("&nbsp;")}_.set(r.current,{opacity:0,y:50});const d=_.timeline({scrollTrigger:{trigger:i,start:"top 75%",toggleActions:"play none none none"}});return d.to(o.current?.querySelectorAll("span")||[],{opacity:1,y:0,duration:.6,stagger:.07,ease:"power3.out"},"-=0.25").to(r.current,{opacity:1,y:0,duration:.8,stagger:.15,ease:"power3.out"},"-=0.25").fromTo(n.current,{opacity:0,y:20},{opacity:1,y:0,duration:.8,ease:"power3.out"},"-=0.4"),()=>d.kill()},[]),t.jsxs("div",{ref:a,className:"section-wrapper",id:"el-problema",children:[t.jsx("section",{ref:e,className:"section-inner",style:{background:"var(--nx-void)",paddingLeft:"var(--nx-section-px)",paddingRight:"var(--nx-section-px)",display:"flex",alignItems:"center"},children:t.jsxs("div",{style:{maxWidth:"1280px",margin:"0 auto",width:"100%"},children:[t.jsx("h2",{ref:o,className:"nx-h2",style:{maxWidth:"700px",marginBottom:"5rem"},children:"Hay vacíos que el sistema educativo colombiano tiene pendiente cubrir — y que afectan a quienes más merecen protección."}),t.jsx("div",{className:"nx-problem-grid",style:{display:"grid",gridTemplateColumns:"repeat(3, 1fr)",gap:"2.5rem"},children:Mi.map(({id:i,icon:l,title:s,body:d},h)=>t.jsxs("div",{ref:f=>r.current[h]=f,className:"nx-problem-card nx-reveal",style:{borderTop:"1px solid var(--nx-border)",paddingTop:"2rem"},children:[t.jsx("div",{className:"nx-icon",style:{marginBottom:"1.5rem"},children:l}),t.jsx("h3",{className:"nx-h3",style:{marginBottom:"0.85rem"},children:s}),t.jsx("p",{className:"nx-body",style:{fontSize:"0.9rem"},children:d})]},i))}),t.jsx("div",{ref:n,className:"nx-reveal",style:{marginTop:"4.5rem",paddingTop:"2.5rem",borderTop:"1px solid var(--nx-border)",display:"flex",justifyContent:"center",opacity:0},children:t.jsx("p",{style:{maxWidth:"640px",textAlign:"center",fontSize:"1rem",lineHeight:1.75,color:"var(--nx-text)",fontStyle:"italic"},children:"NEXO no es una carga más. Es la infraestructura que cierra estos tres vacíos simultáneamente, en tiempo real, sin depender de la conexión a internet de las instituciones."})})]})}),t.jsx("style",{children:`
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
      `})]})}const so=[{num:"01",title:"El estudiante llega",body:"Coloca su huella en el nodo al entrar al salón. El registro ocurre al instante."},{num:"02",title:"El sistema detecta la ausencia",body:"Si un estudiante no registró presencia, NEXO lo identifica automáticamente y notifica al acudiente vía WhatsApp — sin intervención humana, sin formularios, sin demoras."},{num:"03",title:"La institución tiene visibilidad completa",body:"Coordinadores y rectores tienen a su disposición un panel en tiempo real con la información de la institución. Los profesores tienen al alcance de un botón su operación diaria: comunicación, registros, citaciones y más."},{num:"04",title:"Los patrones emergen solos",body:"Salidas frecuentes al baño, llegadas tarde recurrentes, evasiones entre clases — NEXO cruza la información y genera alertas antes de que el problema escale."}];function Li(){const a=m.useRef(),e=m.useRef(),o=m.useRef(),r=m.useRef([]),n=m.useRef(),i=m.useRef(),l=m.useRef(),s=m.useRef();return fr(e),Ct(a,e),m.useEffect(()=>{const d=a.current,h=o.current;if(!d||!h)return;if(window.innerWidth<=768){_.set([n.current,i.current,l.current,s.current],{opacity:1,y:0}),_.set(r.current,{opacity:1,scale:1}),r.current.forEach(g=>{if(!g)return;const c=g.querySelector(".nx-timeline__node");c&&(c.style.borderColor="var(--nx-green)",c.style.color="var(--nx-green)",c.style.backgroundColor="rgba(45, 110, 48, 0.08)")});return}_.set(r.current,{opacity:0,scale:.9});const x=_.context(()=>{_.fromTo([n.current,i.current,l.current],{opacity:0,y:30},{opacity:1,y:0,duration:.85,stagger:.15,ease:"power3.out",scrollTrigger:{trigger:d,start:"top 75%",toggleActions:"play none none none"}}),_.to(h,{strokeDashoffset:0,ease:"none",scrollTrigger:{trigger:d,start:"top top",end:"bottom bottom",scrub:1}});const g=[0,45,90,135];so.forEach((c,y)=>{P.create({trigger:d,start:`top -${g[y]}%`,toggleActions:"play none none none",onEnter:()=>{_.to(r.current[y],{opacity:1,scale:1,duration:.6,ease:"power3.out"}),_.to(r.current[y].querySelector(".nx-timeline__node"),{borderColor:"var(--nx-green)",color:"var(--nx-green)",backgroundColor:"rgba(45, 110, 48, 0.08)",boxShadow:"0 0 20px rgba(45, 110, 48, 0.3)",duration:.4})}})}),_.fromTo(s.current,{opacity:0,y:15},{opacity:1,y:0,duration:.6,ease:"power3.out",scrollTrigger:{trigger:d,start:"top -155%",toggleActions:"play none none none"}})});return()=>x.revert()},[]),t.jsxs("div",{ref:a,className:"section-wrapper section-wrapper--tall",id:"como-funciona",style:{height:"380vh"},children:[t.jsx("section",{ref:e,className:"section-inner",style:{background:"var(--nx-deep)",paddingLeft:"var(--nx-section-px)",paddingRight:"var(--nx-section-px)",display:"flex",alignItems:"center"},children:t.jsxs("div",{style:{maxWidth:"1280px",margin:"0 auto",width:"100%"},children:[t.jsx("div",{ref:n,className:"nx-eyebrow",style:{opacity:0},children:"El sistema"}),t.jsx("h2",{ref:i,className:"nx-h2",style:{maxWidth:"700px",marginBottom:"1rem",opacity:0},children:"Así opera NEXO."}),t.jsx("p",{ref:l,className:"nx-body",style:{maxWidth:"520px",marginBottom:"5rem",opacity:0},children:"Sin capacitaciones de semanas. Sin cambios de hábito forzados."}),t.jsxs("div",{className:"nx-timeline",style:{position:"relative"},children:[t.jsx("div",{style:{position:"absolute",top:"1.25rem",left:"calc(1.25rem + 20px)",right:"calc(1.25rem + 20px)",height:"2px",background:"var(--nx-border)"},"aria-hidden":"true"}),t.jsx("svg",{style:{position:"absolute",top:"1.25rem",left:"calc(1.25rem + 20px)",right:"calc(1.25rem + 20px)",width:"calc(100% - 2.5rem - 40px)",height:"2px",pointerEvents:"none",zIndex:1},"aria-hidden":"true",children:t.jsx("line",{ref:o,x1:"0",y1:"1",x2:"100%",y2:"1",stroke:"var(--nx-green)",strokeWidth:"2",strokeDasharray:"1200",strokeDashoffset:"1200",style:{filter:"drop-shadow(0 0 4px rgba(45, 110, 48, 0.6))"}})}),so.map(({num:d,title:h,body:f},x)=>t.jsxs("div",{ref:g=>r.current[x]=g,className:"nx-timeline__step",style:{position:"relative",zIndex:2},children:[t.jsx("div",{className:"nx-timeline__node",children:d}),t.jsx("div",{className:"nx-timeline__title",children:h}),t.jsx("p",{className:"nx-timeline__body",children:f})]},d))]}),t.jsx("p",{ref:s,className:"nx-micro",style:{textAlign:"center",marginTop:"4rem",paddingTop:"2.5rem",borderTop:"1px solid var(--nx-border)",opacity:0},children:"Todo esto ocurre sin internet · Con batería de respaldo de 12 horas · Con conectividad M2M independiente"})]})}),t.jsx("style",{children:`
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
      `})]})}const lo=[{before:"Lista de asistencia manual — tiempo de cátedra que no vuelve",after:"Registro automático en menos de 1 segundo por estudiante"},{before:"Los acudientes se enteran de la inasistencia días después",after:"Notificación vía WhatsApp en tiempo real, el mismo momento"},{before:"Los coordinadores no saben quién salió ni cuántas veces",after:"Panel de alertas con patrones detectados automáticamente"},{before:"Los registros existen en papel, vulnerables y dispersos",after:"Registro automatizado digital disponible para su descarga en Word o Excel"},{before:"Si se va la luz o el internet, el sistema colapsa",after:"Operación autónoma: batería 12h + conectividad M2M propia"}];function Ni(){const a=m.useRef(),e=m.useRef(),o=m.useRef(),r=m.useRef(),n=m.useRef(),i=m.useRef(),l=m.useRef();return Ct(a,e),m.useEffect(()=>{const s=a.current,d=e.current;if(!s||!d)return;if(window.innerWidth<=768){_.set([i.current,l.current,r.current,n.current,o.current],{opacity:1,x:0,y:0});return}_.set(i.current,{opacity:0,x:-60}),_.set(l.current,{opacity:0,x:60}),_.set(d.querySelectorAll(".nx-ba-row"),{opacity:0,y:16});const f=_.timeline({scrollTrigger:{trigger:s,start:"top 75%",toggleActions:"play none none none"}});return f.fromTo(o.current,{opacity:0,y:-10},{opacity:1,y:0,duration:.55,ease:"power3.out"}).fromTo(r.current,{opacity:0,y:40},{opacity:1,y:0,duration:.9,ease:"expo.out"},"-=0.3").fromTo(n.current,{opacity:0,y:18},{opacity:1,y:0,duration:.75,ease:"power3.out"},"-=0.5").to(i.current,{opacity:1,x:0,duration:1,ease:"expo.out"},"-=0.25").to(l.current,{opacity:1,x:0,duration:1,ease:"expo.out"},"-=1.0").to(d.querySelectorAll(".nx-ba-row"),{opacity:1,y:0,duration:.55,ease:"power3.out",stagger:.08},"-=0.6"),()=>f.kill()},[]),t.jsxs("div",{ref:a,className:"section-wrapper",id:"propuesta-de-valor",children:[t.jsx("section",{ref:e,className:"section-inner",style:{background:"var(--nx-void)",paddingLeft:"var(--nx-section-px)",paddingRight:"var(--nx-section-px)",display:"flex",alignItems:"center"},children:t.jsxs("div",{style:{maxWidth:"1280px",margin:"0 auto",width:"100%"},children:[t.jsx("div",{ref:o,className:"nx-eyebrow",style:{marginBottom:"1rem",opacity:0},children:"Transformación"}),t.jsxs("h2",{ref:r,className:"nx-h2",style:{maxWidth:"720px",marginBottom:"1rem",opacity:0},children:["De la operación reactiva",t.jsx("br",{}),"a la custodia proactiva."]}),t.jsx("p",{ref:n,className:"nx-body",style:{maxWidth:"580px",marginBottom:"3.5rem",opacity:0},children:"Las instituciones que operan con NEXO no esperan que algo ocurra para actuar. Saben qué ocurre, cuándo ocurre y quién es responsable — antes de que escale."}),t.jsxs("div",{className:"nx-ba-table",children:[t.jsxs("div",{ref:i,className:"nx-ba-col nx-ba-col--before",style:{opacity:0},children:[t.jsx("div",{className:"nx-ba-header nx-ba-header--before",children:"Sin NEXO"}),lo.map(({before:s})=>t.jsxs("div",{className:"nx-ba-row",children:[t.jsx("span",{className:"nx-ba-dot nx-ba-dot--before"}),s]},s))]}),t.jsx("div",{className:"nx-ba-divider","aria-hidden":"true",children:t.jsx("svg",{width:"18",height:"18",viewBox:"0 0 18 18",fill:"none",children:t.jsx("path",{d:"M4 9h10M10 5l4 4-4 4",stroke:"var(--nx-blue)",strokeWidth:"1.5",strokeLinecap:"round",strokeLinejoin:"round"})})}),t.jsxs("div",{ref:l,className:"nx-ba-col nx-ba-col--after",style:{opacity:0},children:[t.jsx("div",{className:"nx-ba-header nx-ba-header--after",children:"Con NEXO"}),lo.map(({after:s})=>t.jsxs("div",{className:"nx-ba-row nx-ba-row--after",children:[t.jsx("span",{className:"nx-ba-dot nx-ba-dot--after"}),s]},s))]})]})]})}),t.jsx("style",{children:`
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
      `})]})}const co=[{id:"steel",label:"Acero inoxidable",meaning:"Resiste el uso intensivo diario de cientos de estudiantes sin degradarse.",hotspotPos:{top:"20%",left:"28%"}},{id:"battery",label:"Batería 12 horas",meaning:"Opera durante cortes de luz sin interrupciones. Sin excusas.",hotspotPos:{top:"45%",left:"14%"}},{id:"sim",label:"Conectividad M2M",meaning:"Tiene su propia SIM Card. No depende del WiFi de la institución.",hotspotPos:{top:"68%",left:"26%"}},{id:"encrypt",label:"Encriptado de extremo a extremo",meaning:"Los datos biométricos viajan y se almacenan con encriptación completa en cada capa del sistema.",hotspotPos:{top:"30%",right:"18%"}},{id:"warranty",label:"Cobertura total o parcial ante daños",meaning:"",hotspotPos:{top:"62%",right:"14%"}}];function zi({spec:a,isActive:e,onClick:o}){return t.jsxs("div",{className:"nx-hotspot",style:{position:"absolute",...a.hotspotPos,zIndex:10},onClick:()=>o(a.id),onKeyDown:r=>r.key==="Enter"&&o(a.id),role:"button",tabIndex:0,"aria-label":`Ver detalle: ${a.label}`,"aria-pressed":e,children:[t.jsx("div",{className:"nx-hotspot__ring"}),t.jsx("div",{className:"nx-hotspot__dot",style:{transform:e?"scale(1.4)":"scale(1)",boxShadow:e?"0 0 0 4px rgba(45, 110, 48, 0.3)":"none",transition:"transform 0.2s var(--nx-ease), box-shadow 0.2s"}}),e&&t.jsxs("div",{role:"tooltip",style:{position:"absolute",bottom:"calc(100% + 10px)",left:"50%",transform:"translateX(-50%)",background:"var(--nx-surface)",border:"1px solid var(--nx-border)",borderRadius:"0.75rem",padding:"0.75rem 1rem",width:"200px",pointerEvents:"none",animation:"tooltipIn 0.2s var(--nx-ease)"},children:[t.jsx("div",{style:{fontSize:"0.68rem",fontWeight:700,color:"var(--nx-green)",textTransform:"uppercase",letterSpacing:"0.08em",marginBottom:"0.3rem"},children:a.label}),t.jsx("div",{style:{fontSize:"0.78rem",color:"var(--nx-text)",lineHeight:1.55},children:a.meaning}),t.jsx("div",{style:{position:"absolute",bottom:"-5px",left:"50%",transform:"translateX(-50%) rotate(45deg)",width:"8px",height:"8px",background:"var(--nx-surface)",borderRight:"1px solid var(--nx-border)",borderBottom:"1px solid var(--nx-border)"}})]})]})}function Ai(){const a=m.useRef(),e=m.useRef(),[o,r]=m.useState(null),[n,i]=m.useState(1.12),[l,s]=m.useState(!1);fr(e),m.useEffect(()=>{const h=()=>s(window.innerWidth<=768);return h(),window.addEventListener("resize",h),()=>window.removeEventListener("resize",h)},[]),Ct(a,e),m.useEffect(()=>{const h=a.current,f=e.current;if(!h||!f)return;if(window.innerWidth<=768){i(1.15),_.set(document.querySelectorAll("#el-nodo .nx-hotspot"),{display:"none"});return}const g=f.querySelectorAll(".nx-hotspot");_.set(g,{opacity:0,scale:0});const c=_.timeline({scrollTrigger:{trigger:h,start:"top 75%",toggleActions:"play none none none"}}),y={val:1.12};return c.to(y,{val:1,duration:1.4,ease:"power2.out",onUpdate:()=>{i(y.val)}}),c.to(g,{opacity:1,scale:1,duration:.5,stagger:.18,ease:"back.out(1.7)"},"-=0.1"),()=>c.kill()},[]);const d=h=>r(f=>f===h?null:h);return t.jsxs("div",{ref:a,className:"section-wrapper section-wrapper--tall",id:"el-nodo",children:[t.jsx("section",{ref:e,className:"section-inner",style:{background:"var(--nx-deep)",overflow:l?"visible":"hidden",paddingLeft:"var(--nx-section-px)",paddingRight:"var(--nx-section-px)",display:"flex",alignItems:"center"},children:t.jsxs("div",{style:{maxWidth:"1280px",margin:"0 auto",width:"100%"},children:[t.jsx("div",{className:"nx-eyebrow nx-reveal",children:"El hardware"}),t.jsx("h2",{className:"nx-h2 nx-reveal nx-reveal-delay-1",style:{maxWidth:"680px",marginBottom:"1rem"},children:"Construido para durar en las condiciones reales de una institución educativa colombiana."}),t.jsx("p",{className:"nx-body nx-reveal nx-reveal-delay-2",style:{maxWidth:"520px",marginBottom:"4rem"},children:"No diseñado en un laboratorio ideal. Diseñado para cortes de luz, para humedad, para el uso diario de cientos de estudiantes — y para seguir funcionando."}),t.jsxs("div",{className:"nx-node-grid",style:{display:"grid",gridTemplateColumns:"1fr 1fr",gap:"4rem",alignItems:"center"},children:[t.jsxs("div",{className:"nx-node-canvas-wrap nx-reveal nx-reveal-delay-3",style:{position:"relative",height:"520px"},children:[t.jsx("div",{style:{width:"100%",height:"100%",borderRadius:l?"0":"1.25rem",overflow:l?"visible":"hidden"},children:t.jsx(Ln,{type:"solo",scale:n,coldLight:!0,interactive:!0})}),co.map(h=>t.jsx(zi,{spec:h,isActive:o===h.id,onClick:d},h.id)),t.jsx("div",{"aria-hidden":"true",className:"nx-cursor-hint-desktop",style:{position:"absolute",bottom:"1rem",left:"50%",transform:"translateX(-50%)",fontSize:"0.65rem",color:"var(--nx-muted-2)",letterSpacing:"0.1em",textTransform:"uppercase",whiteSpace:"nowrap",pointerEvents:"none"},children:"Rota con el cursor · Toca los puntos"})]}),t.jsxs("div",{className:"nx-node-specs nx-reveal nx-reveal-delay-4",children:[co.map(({id:h,label:f,meaning:x},g)=>t.jsxs("div",{onClick:()=>d(h),role:"button",tabIndex:0,onKeyDown:c=>c.key==="Enter"&&d(h),"aria-pressed":o===h,style:{display:"flex",alignItems:"flex-start",gap:"1rem",padding:"1.4rem 0.75rem",borderBottom:"1px solid var(--nx-border)",cursor:"pointer",borderRadius:"0.5rem",background:o===h?"rgba(45, 110, 48, 0.05)":"transparent",transition:"background 0.25s"},children:[t.jsx("div",{style:{width:"28px",height:"28px",borderRadius:"50%",flexShrink:0,border:`1.5px solid ${o===h?"var(--nx-green)":"var(--nx-border)"}`,display:"flex",alignItems:"center",justifyContent:"center",fontSize:"0.65rem",fontWeight:700,color:o===h?"var(--nx-green)":"var(--nx-muted)",marginTop:"2px",transition:"border-color 0.25s, color 0.25s"},children:String(g+1).padStart(2,"0")}),t.jsxs("div",{children:[t.jsx("div",{style:{fontWeight:700,fontSize:"0.9rem",marginBottom:"0.3rem",color:o===h?"var(--nx-white)":"var(--nx-text)",transition:"color 0.25s"},children:f}),t.jsx("div",{style:{fontSize:"0.82rem",color:"var(--nx-muted)",lineHeight:1.55},children:x})]})]},h)),t.jsx("p",{className:"nx-micro",style:{marginTop:"1.5rem",paddingLeft:"0.75rem"},children:"El 70% de los costos de daño por causas naturales o ambientales son cubiertos por NEXO durante los primeros 5 años."})]})]})]})}),t.jsx("style",{children:`
        .nx-hotspot__tooltip { z-index: 20; }

        @media (max-width: 768px) {
          #el-nodo .nx-node-grid {
            grid-template-columns: 1fr !important;
            gap: 2rem !important;
          }
          #el-nodo .nx-node-canvas-wrap {
            height: 420px !important;
            border-radius: 1rem !important;
            overflow: hidden !important;
            margin-bottom: 1.5rem !important;
          }
          .nx-hotspot { display: none !important; }
          /* Hide desktop cursor hint, show mobile touch hint instead */
          #el-nodo .nx-cursor-hint-desktop { display: none !important; }
          #el-nodo .nx-node-specs { padding-left: 0 !important; padding-top: 1.5rem !important; }
          #el-nodo .nx-node-specs > div {
            padding: 1rem 0.5rem !important;
            gap: 0.75rem !important;
          }
        }
      `})]})}const uo=[{id:"rector",label:"Rector",icon:t.jsxs("svg",{width:"24",height:"24",viewBox:"0 0 24 24",fill:"none",stroke:"currentColor",strokeWidth:"1.25",strokeLinecap:"round",strokeLinejoin:"round",children:[t.jsx("circle",{cx:"12",cy:"8",r:"4"}),t.jsx("path",{d:"M4 20c0-4 3.6-7 8-7s8 3 8 7"}),t.jsx("path",{d:"M17 4l2 2-2 2"})]}),headline:"La firma institucional queda protegida.",body:"Los rectores tienen acceso centralizado a la información de su institución — lo que ocurre, cuándo ocurre y qué acciones se tomaron. Todo disponible para accionar con respaldo real.",features:["Auditoría completa con marca de tiempo por acción","Informes descargables listos para entes de control"]},{id:"coordinador",label:"Coordinador",icon:t.jsxs("svg",{width:"24",height:"24",viewBox:"0 0 24 24",fill:"none",stroke:"currentColor",strokeWidth:"1.25",strokeLinecap:"round",strokeLinejoin:"round",children:[t.jsx("rect",{x:"2",y:"3",width:"20",height:"14",rx:"2"}),t.jsx("line",{x1:"8",y1:"21",x2:"16",y2:"21"}),t.jsx("line",{x1:"12",y1:"17",x2:"12",y2:"21"}),t.jsx("line",{x1:"6",y1:"8",x2:"18",y2:"8"}),t.jsx("line",{x1:"6",y1:"12",x2:"13",y2:"12"})]}),headline:"Los problemas se detectan antes de escalar.",body:"Los coordinadores ven evasiones entre clases, salidas frecuentes y llegadas tarde recurrentes — todo en un panel en tiempo real, con alertas automáticas configurables por umbral antes de que cualquier situación se convierta en incidente.",features:["Panel de patrones y anomalías en tiempo real","Alertas automáticas configurables por umbral","Historial completo por estudiante a disposición del coordinador"]},{id:"profesor",label:"Profesor",icon:t.jsxs("svg",{width:"24",height:"24",viewBox:"0 0 24 24",fill:"none",stroke:"currentColor",strokeWidth:"1.25",strokeLinecap:"round",strokeLinejoin:"round",children:[t.jsx("path",{d:"M12 2L2 7l10 5 10-5-10-5z"}),t.jsx("path",{d:"M2 17l10 5 10-5"}),t.jsx("path",{d:"M2 12l10 5 10-5"})]}),headline:"La carga administrativa de los docentes se reduce a gran escala, permitiendo orientar ese tiempo al desarrollo pedagógico.",body:"El registro de asistencia ocurre automáticamente. Los docentes pueden citar acudientes con un botón, reportar daños o incidentes desde su teléfono, y dedicar el tiempo de clase exclusivamente a enseñar.",features:["Asistencia automática — sin intervención manual","Citar acudientes desde el móvil en un toque","Reportes de incidentes y daños desde la app"]}];function Pi(){const a=m.useRef(),e=m.useRef(),o=m.useRef(),r=m.useRef(),[n,i]=m.useState(0),l=m.useRef(0),s=m.useRef(!1);fr(e),Ct(a,e),m.useEffect(()=>{const f=window.innerWidth<=768,x=o.current?.querySelectorAll(".nx-tab");if(f){x&&_.set(x,{opacity:1,y:0});return}x&&_.set(x,{opacity:0,y:15});const g=P.create({trigger:a.current,start:"top 75%",toggleActions:"play none none none",onEnter:()=>{x&&_.to(x,{opacity:1,y:0,duration:.6,stagger:.08,ease:"power3.out"})}});return()=>g.kill()},[]);const d=m.useCallback(f=>{f===l.current||s.current||(s.current=!0,_.to(r.current,{opacity:0,y:-12,duration:.18,ease:"power2.in",onComplete:()=>{i(f),l.current=f}}))},[]);m.useEffect(()=>{r.current&&_.fromTo(r.current,{opacity:0,y:12},{opacity:1,y:0,duration:.28,ease:"power3.out",onComplete:()=>{s.current=!1}})},[n]);const h=uo[n];return t.jsxs("div",{ref:a,className:"section-wrapper",id:"roles",children:[t.jsx("section",{ref:e,className:"section-inner",style:{background:"var(--nx-void)",paddingLeft:"var(--nx-section-px)",paddingRight:"var(--nx-section-px)",display:"flex",alignItems:"center"},children:t.jsxs("div",{style:{maxWidth:"1280px",margin:"0 auto",width:"100%"},children:[t.jsx("div",{className:"nx-eyebrow nx-reveal",children:"Por rol"}),t.jsx("h2",{className:"nx-h2 nx-reveal nx-reveal-delay-1",style:{maxWidth:"700px",marginBottom:"1rem"},children:"NEXO opera diferente para cada rol."}),t.jsx("p",{className:"nx-body nx-reveal nx-reveal-delay-2",style:{maxWidth:"500px",marginBottom:"3.5rem"},children:"Pero todos ven lo mismo: control total."}),t.jsx("div",{ref:o,className:"nx-tabs",role:"tablist","aria-label":"Roles de usuario",children:uo.map((f,x)=>t.jsx("button",{id:`tab-${f.id}`,className:`nx-tab${n===x?" active":""}`,onClick:()=>d(x),"aria-selected":n===x,"aria-controls":`panel-${f.id}`,role:"tab",type:"button",children:f.label},f.id))}),t.jsxs("div",{ref:r,className:"nx-role-panel-grid",id:`panel-${h.id}`,role:"tabpanel","aria-labelledby":`tab-${h.id}`,style:{display:"grid",gridTemplateColumns:"auto 1fr",gap:"3.5rem",alignItems:"start",opacity:1,willChange:"opacity, transform"},children:[t.jsx("div",{className:"nx-icon",style:{width:"3.5rem",height:"3.5rem",borderRadius:"1rem",marginTop:"0.25rem"},children:h.icon}),t.jsxs("div",{children:[t.jsx("div",{style:{fontSize:"0.7rem",fontWeight:700,letterSpacing:"0.12em",textTransform:"uppercase",color:"var(--nx-blue)",marginBottom:"0.75rem"},children:h.label}),t.jsx("h3",{style:{fontSize:"1.4rem",fontWeight:800,color:"var(--nx-white)",marginBottom:"0.85rem",lineHeight:1.2},children:h.headline}),t.jsx("p",{className:"nx-body",style:{marginBottom:"2rem",maxWidth:"560px"},children:h.body}),t.jsx("ul",{className:"nx-role-features",style:{display:"flex",flexDirection:"column",gap:"0.75rem",listStyle:"none",padding:0},children:h.features.map(f=>t.jsxs("li",{style:{display:"flex",alignItems:"center",gap:"0.85rem",fontSize:"0.875rem",color:"var(--nx-text)"},children:[t.jsxs("svg",{width:"16",height:"16",viewBox:"0 0 16 16",fill:"none","aria-hidden":!0,children:[t.jsx("circle",{cx:"8",cy:"8",r:"7",stroke:"var(--nx-blue)",strokeWidth:"1"}),t.jsx("path",{d:"M5 8l2 2 4-4",stroke:"var(--nx-blue)",strokeWidth:"1.25",strokeLinecap:"round",strokeLinejoin:"round"})]}),f]},f))})]})]})]})}),t.jsx("style",{children:`
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
      `})]})}const Oi=m.forwardRef(function({href:e,filename:o="nexo.apk",children:r,className:n="",id:i,...l},s){const d=m.useRef();m.useImperativeHandle(s,()=>d.current);const h=f=>{f.preventDefault(),_.timeline().to(d.current,{scale:.92,duration:.1,ease:"power2.in"}).to(d.current,{scale:1.05,duration:.2,ease:"elastic.out(1, 0.3)"}).to(d.current,{scale:1,duration:.15,ease:"power2.out"}),window.open(e,"_blank","noopener,noreferrer")};return t.jsx("button",{ref:d,onClick:h,className:n,id:i,style:{...l.style},...l,children:r})}),Wi=[{id:"android",name:"Android",glowColor:"rgba(61,220,132,0.35)",href:"https://nexo-bay-mu.vercel.app/app/instalar/android",icon:t.jsxs("svg",{width:"56",height:"56",viewBox:"0 0 56 56",fill:"none",stroke:"currentColor",strokeWidth:"1.1",strokeLinecap:"round",strokeLinejoin:"round",children:[t.jsx("path",{d:"M14 20h28v24a4 4 0 01-4 4H18a4 4 0 01-4-4V20z"}),t.jsx("path",{d:"M20 20V14a8 8 0 0116 0v6"}),t.jsx("circle",{cx:"21",cy:"33",r:"2",fill:"currentColor",stroke:"none"}),t.jsx("circle",{cx:"35",cy:"33",r:"2",fill:"currentColor",stroke:"none"}),t.jsx("line",{x1:"10",y1:"26",x2:"10",y2:"36"}),t.jsx("line",{x1:"46",y1:"26",x2:"46",y2:"36"})]})},{id:"ios",name:"iOS",glowColor:"rgba(180,180,185,0.35)",href:"https://nexo-bay-mu.vercel.app/app/instalar/ios",icon:t.jsxs("svg",{width:"56",height:"56",viewBox:"0 0 56 56",fill:"none",stroke:"currentColor",strokeWidth:"1.1",strokeLinecap:"round",strokeLinejoin:"round",children:[t.jsx("path",{d:"M37 4C34 4 31 6 28 6s-6-2-9-2C12 4 6 10 6 19c0 13 8 31 14 31 3 0 4-2 8-2s5 2 8 2c6 0 14-18 14-29C50 10 44 4 37 4z"}),t.jsx("path",{d:"M28 6V2"})]})},{id:"windows",name:"Windows",glowColor:"rgba(0,120,212,0.35)",href:"https://nexo-bay-mu.vercel.app/app/instalar/windows",icon:t.jsxs("svg",{width:"56",height:"56",viewBox:"0 0 56 56",fill:"none",stroke:"currentColor",strokeWidth:"1.1",strokeLinecap:"round",strokeLinejoin:"round",children:[t.jsx("rect",{x:"4",y:"4",width:"22",height:"22",rx:"2"}),t.jsx("rect",{x:"30",y:"4",width:"22",height:"22",rx:"2"}),t.jsx("rect",{x:"4",y:"30",width:"22",height:"22",rx:"2"}),t.jsx("rect",{x:"30",y:"30",width:"22",height:"22",rx:"2"})]})},{id:"mac",name:"Mac",glowColor:"rgba(180,180,185,0.35)",href:"https://nexo-bay-mu.vercel.app/app/instalar/mac",icon:t.jsxs("svg",{width:"56",height:"56",viewBox:"0 0 56 56",fill:"none",stroke:"currentColor",strokeWidth:"1.1",strokeLinecap:"round",strokeLinejoin:"round",children:[t.jsx("rect",{x:"6",y:"6",width:"44",height:"32",rx:"4"}),t.jsx("line",{x1:"2",y1:"48",x2:"54",y2:"48"}),t.jsx("line",{x1:"20",y1:"38",x2:"36",y2:"38"}),t.jsx("line",{x1:"28",y1:"38",x2:"28",y2:"48"})]})},{id:"linux",name:"Linux",glowColor:"rgba(255,185,0,0.30)",href:"https://nexo-bay-mu.vercel.app/app/instalar/linux",icon:t.jsxs("svg",{width:"56",height:"56",viewBox:"0 0 56 56",fill:"none",stroke:"currentColor",strokeWidth:"1.1",strokeLinecap:"round",strokeLinejoin:"round",children:[t.jsx("path",{d:"M28 4c-11 0-16 8-16 18v4c0 3-1 6-3 9C7 38 6 40 6 42c0 3 4 6 11 6 3 0 6-1 8-3 1 1 2 1 3 1s2 0 3-1c2 2 5 3 8 3 7 0 11-3 11-6 0-2-1-4-3-7-2-3-3-6-3-9v-4C44 12 39 4 28 4z"}),t.jsx("circle",{cx:"21",cy:"24",r:"2",fill:"currentColor",stroke:"none"}),t.jsx("circle",{cx:"35",cy:"24",r:"2",fill:"currentColor",stroke:"none"}),t.jsx("path",{d:"M22 34c1.5 2 4 3 6 3s4.5-1 6-3"})]})}];function Di({id:a,name:e,icon:o,href:r,glowColor:n}){const i=m.useRef(),l=m.useRef(),s=m.useRef();m.useEffect(()=>{const f=i.current,x=l.current,g=s.current;if(!f)return;const c=T=>{const C=f.getBoundingClientRect(),k=C.left+C.width/2,O=C.top+C.height/2,E=(T.clientX-k)/(C.width/2),fe=(T.clientY-O)/(C.height/2);_.to(g,{rotateX:-fe*8,rotateY:E*8,duration:.25,ease:"power2.out"})},y=()=>{_.to(f,{scale:1.12,y:-6,duration:.3,ease:"power2.out"}),_.to(x,{opacity:1,duration:.3,ease:"power2.out"})},w=()=>{_.to(f,{scale:1,y:0,duration:.25,ease:"power2.inOut"}),_.to(g,{rotateX:0,rotateY:0,duration:.35,ease:"power2.inOut"}),_.to(x,{opacity:0,duration:.25,ease:"power2.inOut"})};if(!window.matchMedia("(pointer: coarse)").matches)return f.addEventListener("mouseenter",y),f.addEventListener("mouseleave",w),f.addEventListener("mousemove",c),()=>{f.removeEventListener("mouseenter",y),f.removeEventListener("mouseleave",w),f.removeEventListener("mousemove",c)}},[]);const d={display:"flex",flexDirection:"column",alignItems:"center",gap:"1rem",padding:"2.25rem 2rem",background:"var(--nx-surface)",border:"1px solid var(--nx-border)",borderRadius:"1.25rem",cursor:"pointer",position:"relative",overflow:"hidden",willChange:"transform",transformStyle:"preserve-3d",textDecoration:"none",transition:"border-color 0.3s"},h=t.jsxs(t.Fragment,{children:[t.jsx("div",{ref:l,"aria-hidden":"true",style:{position:"absolute",inset:0,background:`radial-gradient(circle at center, ${n} 0%, transparent 70%)`,opacity:0,pointerEvents:"none",borderRadius:"1.25rem"}}),t.jsx("div",{ref:s,style:{color:"var(--nx-green)",position:"relative",zIndex:1,transformStyle:"preserve-3d"},children:o}),t.jsx("span",{style:{fontSize:"0.8rem",fontWeight:600,color:"var(--nx-text)",letterSpacing:"0.04em",position:"relative",zIndex:1},children:e})]});return a==="android"?t.jsx(Oi,{ref:i,href:r,id:`download-btn-${a}`,"aria-label":`Descargar NEXO para ${e}`,style:d,onMouseEnter:f=>f.currentTarget.style.borderColor="rgba(45, 110, 48, 0.4)",onMouseLeave:f=>f.currentTarget.style.borderColor="var(--nx-border)",children:h}):t.jsx("a",{ref:i,href:r,target:"_blank",rel:"noopener noreferrer",id:`download-btn-${a}`,"aria-label":`Descargar NEXO para ${e}`,style:d,onMouseEnter:f=>f.currentTarget.style.borderColor="rgba(45, 110, 48, 0.4)",onMouseLeave:f=>f.currentTarget.style.borderColor="var(--nx-border)",children:h})}function Bi(){const a=m.useRef(),e=m.useRef();return fr(e),Ct(a,e),t.jsxs("div",{ref:a,className:"section-wrapper",id:"descarga",children:[t.jsx("section",{ref:e,className:"section-inner",style:{background:"var(--nx-deep)",paddingLeft:"var(--nx-section-px)",paddingRight:"var(--nx-section-px)",display:"flex",alignItems:"center"},children:t.jsxs("div",{style:{maxWidth:"1280px",margin:"0 auto",width:"100%"},children:[t.jsxs("div",{style:{textAlign:"center",marginBottom:"5rem"},children:[t.jsx("div",{className:"nx-eyebrow nx-reveal",style:{justifyContent:"center",display:"flex"},children:"La aplicación"}),t.jsx("h2",{className:"nx-h2 nx-reveal nx-reveal-delay-1",style:{marginBottom:"1rem"},children:"Tu panel de control institucional."}),t.jsx("p",{className:"nx-body nx-reveal nx-reveal-delay-2",style:{maxWidth:"480px",margin:"0 auto"},children:"Disponible para Android, iOS, Windows, Mac y Linux. La misma información, en tiempo real, donde estés."})]}),t.jsx("div",{className:"nx-platform-grid nx-reveal nx-reveal-delay-3",style:{display:"flex",gap:"1.25rem",justifyContent:"center",flexWrap:"wrap"},children:Wi.map(o=>t.jsx(Di,{...o},o.id))})]})}),t.jsx("style",{children:`
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
      `})]})}const Ii=[{id:"biometric",icon:t.jsxs("svg",{width:"22",height:"22",viewBox:"0 0 22 22",fill:"none",stroke:"currentColor",strokeWidth:"1.2",strokeLinecap:"round",strokeLinejoin:"round",children:[t.jsx("path",{d:"M6 7a5 5 0 0110 0"}),t.jsx("path",{d:"M8 11a3 3 0 016 0"}),t.jsx("path",{d:"M11 14v3"}),t.jsx("circle",{cx:"11",cy:"19",r:"1",fill:"currentColor",stroke:"none"})]}),title:"Biometría en el nodo",body:"Los registros biométricos nunca salen del nodo en formato legible."},{id:"encrypt",icon:t.jsxs("svg",{width:"22",height:"22",viewBox:"0 0 22 22",fill:"none",stroke:"currentColor",strokeWidth:"1.2",strokeLinecap:"round",strokeLinejoin:"round",children:[t.jsx("rect",{x:"5",y:"10",width:"12",height:"10",rx:"2"}),t.jsx("path",{d:"M8 10V7a3 3 0 016 0v3"}),t.jsx("circle",{cx:"11",cy:"15",r:"1.5",fill:"currentColor",stroke:"none"})]}),title:"Encriptación E2E",body:"Encriptación de extremo a extremo en cada transmisión de datos."},{id:"audit",icon:t.jsxs("svg",{width:"22",height:"22",viewBox:"0 0 22 22",fill:"none",stroke:"currentColor",strokeWidth:"1.2",strokeLinecap:"round",strokeLinejoin:"round",children:[t.jsx("path",{d:"M9 5H7a2 2 0 00-2 2v11a2 2 0 002 2h8a2 2 0 002-2V7a2 2 0 00-2-2h-2"}),t.jsx("rect",{x:"9",y:"3",width:"4",height:"4",rx:"1"}),t.jsx("line",{x1:"9",y1:"12",x2:"13",y2:"12"}),t.jsx("line",{x1:"9",y1:"16",x2:"11",y2:"16"})]}),title:"Auditoría total",body:"Sabes exactamente quién tocó qué dato y cuándo. Cada acción registrada."},{id:"compliance",icon:t.jsxs("svg",{width:"22",height:"22",viewBox:"0 0 22 22",fill:"none",stroke:"currentColor",strokeWidth:"1.2",strokeLinecap:"round",strokeLinejoin:"round",children:[t.jsx("path",{d:"M11 2L3 6v6c0 4.4 3.4 8.5 8 9.5 4.6-1 8-5.1 8-9.5V6l-8-4z"}),t.jsx("polyline",{points:"8 11 10 13 14 9"})]}),title:"MEN + SIC",body:"Cumplimiento con lineamientos de protección de datos del MEN y la SIC."},{id:"nothirdparty",icon:t.jsxs("svg",{width:"22",height:"22",viewBox:"0 0 22 22",fill:"none",stroke:"currentColor",strokeWidth:"1.2",strokeLinecap:"round",strokeLinejoin:"round",children:[t.jsx("circle",{cx:"11",cy:"11",r:"9"}),t.jsx("line",{x1:"4.9",y1:"4.9",x2:"17.1",y2:"17.1"})]}),title:"Sin terceros",body:"Sin venta de datos. Sin terceros con acceso. Sin publicidad de ningún tipo."}];function Xi(){return t.jsxs("svg",{className:"nx-shield",width:"120",height:"140",viewBox:"0 0 120 140",fill:"none","aria-hidden":"true",children:[t.jsx("path",{d:"M60 8L12 28v38c0 30 20 56 48 64 28-8 48-34 48-64V28L60 8z",stroke:"rgba(45,110,48,0.4)",strokeWidth:"1.5",fill:"none"}),t.jsx("path",{d:"M60 20L24 36v28c0 22 15 42 36 48 21-6 36-26 36-48V36L60 20z",stroke:"rgba(45,110,48,0.6)",strokeWidth:"1",fill:"rgba(45,110,48,0.04)"}),t.jsx("path",{d:"M44 68l12 12 20-20",stroke:"var(--nx-blue)",strokeWidth:"2",strokeLinecap:"round",strokeLinejoin:"round"}),t.jsx("circle",{cx:"60",cy:"68",r:"24",stroke:"rgba(45,110,48,0.15)",strokeWidth:"1",fill:"none"})]})}function Fi(){const a=m.useRef(),e=m.useRef();return fr(e),Ct(a,e),t.jsxs("div",{ref:a,className:"section-wrapper",id:"seguridad",children:[t.jsx("section",{ref:e,className:"section-inner",style:{background:"var(--nx-void)",paddingLeft:"var(--nx-section-px)",paddingRight:"var(--nx-section-px)",display:"flex",alignItems:"center"},children:t.jsx("div",{style:{maxWidth:"1280px",margin:"0 auto",width:"100%"},children:t.jsxs("div",{className:"nx-security-layout",style:{display:"grid",gridTemplateColumns:"1fr 1.4fr",gap:"5rem",alignItems:"center"},children:[t.jsxs("div",{className:"nx-reveal",children:[t.jsx("div",{className:"nx-eyebrow",style:{marginBottom:"1rem"},children:"Seguridad"}),t.jsx("h2",{className:"nx-h2",style:{marginBottom:"1.25rem"},children:"Los datos de tus estudiantes no son un activo de nadie más."}),t.jsx("p",{className:"nx-body",style:{marginBottom:"2.5rem"},children:"NEXO fue diseñado desde cero con protección de datos como principio de arquitectura, no como característica adicional."}),t.jsx(Xi,{})]}),t.jsxs("div",{className:"nx-security-grid nx-reveal nx-reveal-delay-2",children:[Ii.map(({id:o,icon:r,title:n,body:i},l)=>t.jsxs("div",{className:`nx-card nx-reveal nx-reveal-delay-${l+1}`,style:{padding:"1.5rem"},children:[t.jsx("div",{className:"nx-icon",style:{marginBottom:"1rem"},children:r}),t.jsx("h3",{style:{fontSize:"0.9rem",fontWeight:700,color:"var(--nx-white)",marginBottom:"0.4rem"},children:n}),t.jsx("p",{style:{fontSize:"0.8rem",color:"var(--nx-muted)",lineHeight:1.6},children:i})]},o)),t.jsxs("div",{className:"nx-card nx-reveal nx-reveal-delay-5",style:{padding:"1.5rem",background:"rgba(45,110,48,0.05)",borderColor:"rgba(45,110,48,0.2)",display:"flex",flexDirection:"column",justifyContent:"center",alignItems:"center",textAlign:"center",gap:"0.5rem"},children:[t.jsx("div",{style:{fontSize:"1.5rem",fontWeight:800,color:"var(--nx-blue)"},children:"Ley 1581"}),t.jsx("div",{style:{fontSize:"0.72rem",color:"var(--nx-muted)",letterSpacing:"0.06em",textTransform:"uppercase"},children:"Protección de Datos Colombia"})]})]})]})})}),t.jsx("style",{children:`
        @media (max-width: 768px) {
          .nx-security-layout {
            grid-template-columns: 1fr !important;
            gap: 2rem !important;
          }
        }
      `})]})}const qi={privacy:{title:"Política de Privacidad",body:`NEXO S.A.S., identificada con NIT [en trámite], con domicilio en Colombia, actúa como Responsable del Tratamiento de los datos personales recopilados a través de su plataforma de custodia educativa.

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

Fecha de última actualización: mayo de 2026.`}};function Yi({type:a,onClose:e}){const o=m.useRef(),r=qi[a];m.useEffect(()=>{const i=l=>{l.key==="Escape"&&e()};return document.addEventListener("keydown",i),document.body.style.overflow="hidden",()=>{document.removeEventListener("keydown",i),document.body.style.overflow=""}},[e]);const n=i=>{i.target===o.current&&e()};return r?t.jsxs("div",{ref:o,onClick:n,style:{position:"fixed",inset:0,zIndex:9999,background:"rgba(0, 0, 0, 0.75)",backdropFilter:"blur(4px)",display:"flex",alignItems:"center",justifyContent:"center",padding:"2rem",animation:"legalFadeIn 0.2s ease"},children:[t.jsxs("div",{role:"dialog","aria-modal":"true","aria-labelledby":"legal-modal-title",style:{background:"var(--nx-surface, #1a1d23)",border:"1px solid var(--nx-border, #2a2d35)",borderRadius:"1.25rem",maxWidth:"680px",width:"100%",maxHeight:"80vh",display:"flex",flexDirection:"column",overflow:"hidden"},children:[t.jsxs("div",{style:{display:"flex",alignItems:"center",justifyContent:"space-between",padding:"1.5rem 2rem",borderBottom:"1px solid var(--nx-border, #2a2d35)",flexShrink:0},children:[t.jsx("h3",{id:"legal-modal-title",style:{fontSize:"1.1rem",fontWeight:700,color:"var(--nx-white, #f0f2f5)",margin:0},children:r.title}),t.jsx("button",{onClick:e,type:"button","aria-label":"Cerrar",style:{background:"none",border:"1px solid var(--nx-border, #2a2d35)",borderRadius:"0.5rem",width:"36px",height:"36px",display:"flex",alignItems:"center",justifyContent:"center",cursor:"pointer",color:"var(--nx-muted, #8a8f9a)",transition:"color 0.2s, border-color 0.2s"},onMouseEnter:i=>{i.currentTarget.style.color="var(--nx-white)",i.currentTarget.style.borderColor="var(--nx-muted)"},onMouseLeave:i=>{i.currentTarget.style.color="var(--nx-muted)",i.currentTarget.style.borderColor="var(--nx-border)"},children:t.jsxs("svg",{width:"16",height:"16",viewBox:"0 0 16 16",fill:"none",stroke:"currentColor",strokeWidth:"1.5",strokeLinecap:"round",children:[t.jsx("line",{x1:"4",y1:"4",x2:"12",y2:"12"}),t.jsx("line",{x1:"12",y1:"4",x2:"4",y2:"12"})]})})]}),t.jsx("div",{style:{padding:"2rem",overflowY:"auto",fontSize:"0.85rem",lineHeight:1.75,color:"var(--nx-text, #c8ccd4)",fontFamily:"'Plus Jakarta Sans', sans-serif"},children:r.body.split(`
`).map((i,l)=>i.startsWith("**")&&i.endsWith("**")?t.jsx("h4",{style:{fontSize:"0.9rem",fontWeight:700,color:"var(--nx-white, #f0f2f5)",marginTop:"1.75rem",marginBottom:"0.75rem"},children:i.replace(/\*\*/g,"")},l):i.startsWith("• ")?t.jsxs("div",{style:{paddingLeft:"1rem",marginBottom:"0.35rem"},children:[t.jsx("span",{style:{color:"var(--nx-blue, #6b9fff)",marginRight:"0.5rem"},children:"•"}),i.slice(2)]},l):i.trim()===""?t.jsx("div",{style:{height:"0.75rem"}},l):t.jsx("p",{style:{margin:"0 0 0.5rem"},children:i},l))})]}),t.jsx("style",{children:`
        @keyframes legalFadeIn {
          from { opacity: 0; }
          to { opacity: 1; }
        }
      `})]}):null}function Hi(){const a=m.useRef(),e=m.useRef(),o=m.useRef(),r=m.useRef(),n=m.useRef(),i=m.useRef(),l=m.useRef(),[s,d]=m.useState(!1),[h,f]=m.useState(null);return Ct(a,e,{isLast:!0}),m.useEffect(()=>{const x=a.current;if(!x)return;const g=o.current;if(window.innerWidth<=768&&g){_.set(g,{opacity:1}),_.set([r.current,n.current?.querySelectorAll("button, a")||[],i.current,l.current],{opacity:1,y:0});return}if(g){const T=g.textContent.trim();g.innerHTML=T.split("").map(C=>C===" "?'<span style="display:inline-block;width:0.28em">&nbsp;</span>':`<span style="display:inline-block;opacity:0;transform:scale(0.8)">${C}</span>`).join("")}const y=o.current?.querySelectorAll("span")||[],w=_.timeline({scrollTrigger:{trigger:x,start:"top 75%",toggleActions:"play none none none"}});return w.to(y,{opacity:1,scale:1,duration:.8,ease:"expo.out",stagger:.025}).fromTo(r.current,{opacity:0,y:18},{opacity:1,y:0,duration:.75,ease:"power3.out"},"-=0.4").fromTo(n.current?.querySelectorAll("button, a")||[],{opacity:0,scale:.94},{opacity:1,scale:1,duration:.6,ease:"power3.out",stagger:.15},.6).fromTo(i.current,{opacity:0},{opacity:1,duration:.5,ease:"power2.out"},"-=0.1").fromTo(l.current,{opacity:0,y:12},{opacity:1,y:0,duration:.5,ease:"power3.out"},"-=0.2"),()=>w.kill()},[]),t.jsxs(t.Fragment,{children:[s&&t.jsx(qo,{onClose:()=>d(!1)}),h&&t.jsx(Yi,{type:h,onClose:()=>f(null)}),t.jsxs("div",{ref:a,className:"section-wrapper",id:"contacto",children:[t.jsx("section",{ref:e,className:"section-inner",style:{paddingLeft:"var(--nx-section-px)",paddingRight:"var(--nx-section-px)",background:"var(--nx-void)",display:"flex",alignItems:"center"},children:t.jsxs("div",{style:{maxWidth:"760px",margin:"0 auto",textAlign:"center",width:"100%"},children:[t.jsx("div",{className:"nx-eyebrow",style:{display:"flex",justifyContent:"center",marginBottom:"1.25rem"},children:"El próximo paso"}),t.jsx("h2",{ref:o,style:{fontSize:"clamp(1.6rem, 3.2vw, 2.4rem)",fontWeight:800,letterSpacing:"-0.03em",lineHeight:1.35,color:"var(--nx-white)",marginBottom:"1.25rem",overflow:"visible",paddingBottom:"0.25em",wordBreak:"break-word"},"aria-label":"El próximo semestre puede empezar diferente.",children:"El próximo semestre puede empezar diferente."}),t.jsx("p",{ref:r,className:"nx-body",style:{maxWidth:"520px",margin:"0 auto 3rem",opacity:0},children:"La implementación de NEXO es más rápida de lo que se imagina. Una conversación es suficiente para saber si la institución está lista para empezar el proceso."}),t.jsx("div",{ref:n,className:"nx-cta-buttons",style:{display:"flex",gap:"1rem",justifyContent:"center",flexWrap:"wrap",marginBottom:"1.5rem"},children:t.jsx("button",{id:"final-cta-primary",className:"nx-btn-primary",onClick:()=>d(!0),type:"button",style:{fontSize:"0.95rem",padding:"1rem 2rem",opacity:0},children:"Quiero que NEXO llegue a mi institución"})}),t.jsx("p",{ref:i,className:"nx-micro",style:{marginBottom:"4rem",opacity:0},children:"Sin costos de evaluación · Sin compromisos previos al contrato · Con acompañamiento desde el primer contacto"}),t.jsx("div",{className:"nx-divider",style:{marginBottom:"2.5rem"}}),t.jsxs("div",{ref:l,style:{display:"flex",gap:"2.5rem",justifyContent:"center",flexWrap:"wrap",alignItems:"center",opacity:0},children:[t.jsxs("a",{href:"mailto:jhonedisonalvarez21@gmail.com",style:{display:"flex",alignItems:"center",gap:"0.6rem",fontSize:"0.875rem",color:"var(--nx-muted)",transition:"color 0.25s"},onMouseEnter:x=>x.currentTarget.style.color="var(--nx-text)",onMouseLeave:x=>x.currentTarget.style.color="var(--nx-muted)",children:[t.jsxs("svg",{width:"16",height:"16",viewBox:"0 0 16 16",fill:"none",stroke:"currentColor",strokeWidth:"1.25",strokeLinecap:"round",strokeLinejoin:"round","aria-hidden":!0,children:[t.jsx("rect",{x:"1",y:"3",width:"14",height:"10",rx:"1.5"}),t.jsx("polyline",{points:"1,3 8,9 15,3"})]}),"jhonedisonalvarez21@gmail.com"]}),t.jsx("div",{style:{width:"1px",height:"16px",background:"var(--nx-border)"},"aria-hidden":!0}),t.jsxs("a",{href:"https://wa.me/573148622367",target:"_blank",rel:"noopener noreferrer",style:{display:"flex",alignItems:"center",gap:"0.6rem",fontSize:"0.875rem",color:"var(--nx-muted)",transition:"color 0.25s"},onMouseEnter:x=>x.currentTarget.style.color="var(--nx-text)",onMouseLeave:x=>x.currentTarget.style.color="var(--nx-muted)",children:[t.jsx("svg",{width:"16",height:"16",viewBox:"0 0 16 16",fill:"none",stroke:"currentColor",strokeWidth:"1.25",strokeLinecap:"round",strokeLinejoin:"round","aria-hidden":!0,children:t.jsx("path",{d:"M14 10.67c0 .23-.05.45-.16.66a2.74 2.74 0 01-.42.6c-.27.3-.56.45-.88.46-.23 0-.47-.05-.73-.16L8 9.7 3.2 12.23a1.8 1.8 0 01-.73.16 1.4 1.4 0 01-.88-.46 2.74 2.74 0 01-.42-.6A1.6 1.6 0 011 10.67V3.4c0-.62.22-1.15.67-1.6A2.17 2.17 0 013.27 1.1h9.46c.62 0 1.15.23 1.6.7.45.45.67.98.67 1.6v7.27z"})}),"+57 314 862 2367 (WhatsApp)"]})]}),t.jsxs("div",{style:{marginTop:"2.5rem",display:"flex",justifyContent:"center",gap:"0.5rem",flexWrap:"wrap",fontSize:"0.75rem",color:"var(--nx-muted)"},children:[t.jsx("button",{type:"button",onClick:()=>f("privacy"),style:{background:"none",border:"none",color:"var(--nx-muted)",cursor:"pointer",fontSize:"0.75rem",padding:"0.25rem 0.4rem",transition:"color 0.2s"},onMouseEnter:x=>x.currentTarget.style.color="var(--nx-text)",onMouseLeave:x=>x.currentTarget.style.color="var(--nx-muted)",children:"Política de privacidad"}),t.jsx("span",{style:{color:"var(--nx-border)"},children:"|"}),t.jsx("button",{type:"button",onClick:()=>f("treatment"),style:{background:"none",border:"none",color:"var(--nx-muted)",cursor:"pointer",fontSize:"0.75rem",padding:"0.25rem 0.4rem",transition:"color 0.2s"},onMouseEnter:x=>x.currentTarget.style.color="var(--nx-text)",onMouseLeave:x=>x.currentTarget.style.color="var(--nx-muted)",children:"Tratamiento de datos"}),t.jsx("span",{style:{color:"var(--nx-border)"},children:"|"}),t.jsx("button",{type:"button",onClick:()=>f("terms"),style:{background:"none",border:"none",color:"var(--nx-muted)",cursor:"pointer",fontSize:"0.75rem",padding:"0.25rem 0.4rem",transition:"color 0.2s"},onMouseEnter:x=>x.currentTarget.style.color="var(--nx-text)",onMouseLeave:x=>x.currentTarget.style.color="var(--nx-muted)",children:"Términos de uso"})]})]})}),t.jsx("style",{children:`
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
        `})]})]})}function $i(){const a=new Date().getFullYear();return t.jsx("footer",{className:"nx-footer",style:{paddingTop:"3rem",paddingBottom:"3rem",paddingLeft:"var(--nx-section-px)",paddingRight:"var(--nx-section-px)"},children:t.jsxs("div",{style:{maxWidth:"1280px",margin:"0 auto",display:"flex",alignItems:"center",justifyContent:"space-between",gap:"2rem",flexWrap:"wrap"},children:[t.jsx("span",{style:{fontWeight:800,fontSize:"1rem",letterSpacing:"-0.02em",color:"var(--nx-white)"},children:"NEXO"}),t.jsx("nav",{"aria-label":"Legal",children:t.jsx("ul",{style:{display:"flex",gap:"1.75rem",listStyle:"none",flexWrap:"wrap"},children:[{label:"Política de privacidad",href:"#privacidad"},{label:"Tratamiento de datos",href:"#datos"},{label:"Términos de uso",href:"#terminos"}].map(({label:e,href:o})=>t.jsx("li",{children:t.jsx("a",{href:o,style:{fontSize:"0.78rem",color:"var(--nx-muted-2)",transition:"color 0.2s"},onMouseEnter:r=>r.target.style.color="var(--nx-muted)",onMouseLeave:r=>r.target.style.color="var(--nx-muted-2)",children:e})},e))})}),t.jsxs("span",{style:{fontSize:"0.75rem",color:"var(--nx-muted-2)"},children:["© ",a," NEXO. Todos los derechos reservados."]})]})})}function Gi(){const a=m.useRef(),e=m.useRef();return m.useEffect(()=>{if(window.matchMedia("(pointer: coarse)").matches)return;document.body.classList.add("custom-cursor-active");const o=a.current,r=e.current;if(!o||!r)return;let n=0,i=0,l=0,s=0,d=0;const h=.12,f=w=>{n=w.clientX,i=w.clientY,_.set(o,{x:n,y:i})};document.addEventListener("mousemove",f);const x=()=>{l+=(n-l)*h,s+=(i-s)*h,_.set(r,{x:l,y:s}),d=requestAnimationFrame(x)};x();const g=document.querySelectorAll('button, a, [data-cursor-expand], [role="button"], .nx-hotspot, .nx-tab'),c=()=>{r.style.width="48px",r.style.height="48px",r.style.borderColor="rgba(99, 179, 237, 0.8)"},y=()=>{r.style.width="32px",r.style.height="32px",r.style.borderColor="rgba(255,255,255,0.5)"};return g.forEach(w=>{w.addEventListener("mouseenter",c),w.addEventListener("mouseleave",y)}),()=>{cancelAnimationFrame(d),document.body.classList.remove("custom-cursor-active"),document.removeEventListener("mousemove",f),g.forEach(w=>{w.removeEventListener("mouseenter",c),w.removeEventListener("mouseleave",y)})}},[]),typeof window<"u"&&window.matchMedia("(pointer: coarse)").matches?null:t.jsxs(t.Fragment,{children:[t.jsx("div",{id:"cursor-dot",ref:a}),t.jsx("div",{id:"cursor-ring",ref:e})]})}const bn="nexo_cookie_consent",po="1.0",Ui={necessary:{id:"necessary",label:"Cookies necesarias",description:"Esenciales para el funcionamiento básico del sitio. No se pueden desactivar.",required:!0},analytics:{id:"analytics",label:"Cookies analíticas",description:"Nos ayudan a entender cómo los visitantes interactúan con el sitio para mejorar la experiencia.",required:!1},marketing:{id:"marketing",label:"Cookies de marketing",description:"Permiten mostrar contenido y anuncios relevantes según tus intereses.",required:!1},preferences:{id:"preferences",label:"Cookies de preferencias",description:"Recuerdan tus configuraciones y personalizaciones para visitas futuras.",required:!1}};function Vi(){const[a,e]=m.useState(null),[o,r]=m.useState(!1),[n,i]=m.useState(!1);m.useEffect(()=>{const g=localStorage.getItem(bn);if(g)try{const c=JSON.parse(g);if(c.version===po){e(c),r(!1);return}}catch{}setTimeout(()=>r(!0),800)},[]);const l=g=>{const c={version:po,timestamp:new Date().toISOString(),categories:g};localStorage.setItem(bn,JSON.stringify(c)),e(c),r(!1),i(!1)};return{consent:a,showBanner:o,showManager:n,setShowManager:i,acceptAll:()=>{l({necessary:!0,analytics:!0,marketing:!0,preferences:!0})},rejectAll:()=>{l({necessary:!0,analytics:!1,marketing:!1,preferences:!1})},saveCustom:g=>{l({necessary:!0,...g})},resetConsent:()=>{localStorage.removeItem(bn),e(null),r(!0)},hasConsent:g=>a?.categories?.[g]===!0}}/**
 * @license lucide-react v1.16.0 - ISC
 *
 * This source code is licensed under the ISC license.
 * See the LICENSE file in the root directory of this source tree.
 */const Yo=(...a)=>a.filter((e,o,r)=>!!e&&e.trim()!==""&&r.indexOf(e)===o).join(" ").trim();/**
 * @license lucide-react v1.16.0 - ISC
 *
 * This source code is licensed under the ISC license.
 * See the LICENSE file in the root directory of this source tree.
 */const Ki=a=>a.replace(/([a-z0-9])([A-Z])/g,"$1-$2").toLowerCase();/**
 * @license lucide-react v1.16.0 - ISC
 *
 * This source code is licensed under the ISC license.
 * See the LICENSE file in the root directory of this source tree.
 */const Ji=a=>a.replace(/^([A-Z])|[\s-_]+(\w)/g,(e,o,r)=>r?r.toUpperCase():o.toLowerCase());/**
 * @license lucide-react v1.16.0 - ISC
 *
 * This source code is licensed under the ISC license.
 * See the LICENSE file in the root directory of this source tree.
 */const fo=a=>{const e=Ji(a);return e.charAt(0).toUpperCase()+e.slice(1)};/**
 * @license lucide-react v1.16.0 - ISC
 *
 * This source code is licensed under the ISC license.
 * See the LICENSE file in the root directory of this source tree.
 */var vn={xmlns:"http://www.w3.org/2000/svg",width:24,height:24,viewBox:"0 0 24 24",fill:"none",stroke:"currentColor",strokeWidth:2,strokeLinecap:"round",strokeLinejoin:"round"};/**
 * @license lucide-react v1.16.0 - ISC
 *
 * This source code is licensed under the ISC license.
 * See the LICENSE file in the root directory of this source tree.
 */const Zi=a=>{for(const e in a)if(e.startsWith("aria-")||e==="role"||e==="title")return!0;return!1},Qi=m.createContext({}),ea=()=>m.useContext(Qi),ta=m.forwardRef(({color:a,size:e,strokeWidth:o,absoluteStrokeWidth:r,className:n="",children:i,iconNode:l,...s},d)=>{const{size:h=24,strokeWidth:f=2,absoluteStrokeWidth:x=!1,color:g="currentColor",className:c=""}=ea()??{},y=r??x?Number(o??f)*24/Number(e??h):o??f;return m.createElement("svg",{ref:d,...vn,width:e??h??vn.width,height:e??h??vn.height,stroke:a??g,strokeWidth:y,className:Yo("lucide",c,n),...!i&&!Zi(s)&&{"aria-hidden":"true"},...s},[...l.map(([w,T])=>m.createElement(w,T)),...Array.isArray(i)?i:[i]])});/**
 * @license lucide-react v1.16.0 - ISC
 *
 * This source code is licensed under the ISC license.
 * See the LICENSE file in the root directory of this source tree.
 */const Xn=(a,e)=>{const o=m.forwardRef(({className:r,...n},i)=>m.createElement(ta,{ref:i,iconNode:e,className:Yo(`lucide-${Ki(fo(a))}`,`lucide-${a}`,r),...n}));return o.displayName=fo(a),o};/**
 * @license lucide-react v1.16.0 - ISC
 *
 * This source code is licensed under the ISC license.
 * See the LICENSE file in the root directory of this source tree.
 */const ra=[["path",{d:"M12 2a10 10 0 1 0 10 10 4 4 0 0 1-5-5 4 4 0 0 1-5-5",key:"laymnq"}],["path",{d:"M8.5 8.5v.01",key:"ue8clq"}],["path",{d:"M16 15.5v.01",key:"14dtrp"}],["path",{d:"M12 12v.01",key:"u5ubse"}],["path",{d:"M11 17v.01",key:"1hyl5a"}],["path",{d:"M7 14v.01",key:"uct60s"}]],na=Xn("cookie",ra);/**
 * @license lucide-react v1.16.0 - ISC
 *
 * This source code is licensed under the ISC license.
 * See the LICENSE file in the root directory of this source tree.
 */const oa=[["path",{d:"M20 13c0 5-3.5 7.5-7.66 8.95a1 1 0 0 1-.67-.01C7.5 20.5 4 18 4 13V6a1 1 0 0 1 1-1c2 0 4.5-1.2 6.24-2.72a1.17 1.17 0 0 1 1.52 0C14.51 3.81 17 5 19 5a1 1 0 0 1 1 1z",key:"oel41y"}]],ia=Xn("shield",oa);/**
 * @license lucide-react v1.16.0 - ISC
 *
 * This source code is licensed under the ISC license.
 * See the LICENSE file in the root directory of this source tree.
 */const aa=[["path",{d:"M18 6 6 18",key:"1bl5f8"}],["path",{d:"m6 6 12 12",key:"d8bk6v"}]],sa=Xn("x",aa),la=({onAcceptAll:a,onRejectAll:e,onManage:o})=>t.jsxs("div",{className:"cookie-banner",children:[t.jsx("style",{children:`
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
      `}),t.jsxs("div",{className:"cookie-banner__header",children:[t.jsx("div",{className:"cookie-banner__icon",children:t.jsx(na,{size:20})}),t.jsxs("div",{className:"cookie-banner__content",children:[t.jsx("h3",{children:"Usamos cookies"}),t.jsx("p",{children:"Utilizamos cookies para mejorar tu experiencia, analizar el tráfico y personalizar el contenido. Puedes aceptar todas o administrar tus preferencias."})]})]}),t.jsxs("div",{className:"cookie-banner__actions",children:[t.jsx("button",{onClick:a,className:"cookie-banner__btn cookie-banner__btn--primary",children:"Aceptar todas"}),t.jsx("button",{onClick:e,className:"cookie-banner__btn cookie-banner__btn--ghost",children:"Solo necesarias"})]}),t.jsx("div",{className:"cookie-banner__link",children:t.jsx("button",{onClick:o,children:"Administrar cookies"})})]}),ca=({onSave:a,onAcceptAll:e,onClose:o,initialValues:r})=>{const[n,i]=m.useState({necessary:!0,analytics:r?.analytics??!1,marketing:r?.marketing??!1,preferences:r?.preferences??!1});m.useEffect(()=>(document.body.style.overflow="hidden",()=>{document.body.style.overflow="unset"}),[]);const l=d=>{d!=="necessary"&&i(h=>({...h,[d]:!h[d]}))},s=()=>{a(n)};return t.jsxs("div",{className:"cookie-manager-overlay",children:[t.jsx("style",{children:`
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
      `}),t.jsxs("div",{className:"cookie-manager-modal",children:[t.jsxs("div",{className:"cookie-manager-header",children:[t.jsx("h2",{children:"Preferencias de cookies"}),t.jsx("button",{onClick:o,className:"cookie-manager-close","aria-label":"Cerrar",children:t.jsx(sa,{size:20})})]}),t.jsx("div",{className:"cookie-manager-body",children:Object.values(Ui).map(d=>t.jsxs("div",{className:"cookie-category",children:[t.jsxs("div",{className:"cookie-category-header",children:[t.jsx("h3",{className:"cookie-category-title",children:d.label}),t.jsxs("label",{className:"toggle-switch",children:[t.jsx("input",{type:"checkbox",checked:n[d.id],disabled:d.required,onChange:()=>l(d.id)}),t.jsx("span",{className:"toggle-slider"})]})]}),t.jsx("p",{className:"cookie-category-desc",children:d.description})]},d.id))}),t.jsxs("div",{className:"cookie-manager-footer",children:[t.jsx("button",{onClick:s,className:"cookie-manager-btn cookie-manager-btn--primary",children:"Guardar preferencias"}),t.jsx("button",{onClick:e,className:"cookie-manager-btn cookie-manager-btn--secondary",children:"Aceptar todas"})]})]})]})},da=({onClick:a})=>t.jsxs("button",{onClick:a,className:"cookie-floating-btn","aria-label":"Gestionar cookies",children:[t.jsx("style",{children:`
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
      `}),t.jsx(ia,{size:20})]});_.registerPlugin(P);function ua(){const a=Vi();return m.useEffect(()=>{const e=setTimeout(()=>{P.refresh()},1200);let o;const r=()=>{clearTimeout(o),o=setTimeout(()=>{P.refresh()},250)};return window.addEventListener("resize",r),()=>{clearTimeout(e),clearTimeout(o),window.removeEventListener("resize",r)}},[]),t.jsxs(t.Fragment,{children:[t.jsx(Gi,{}),t.jsx(yi,{}),t.jsxs("main",{id:"nx-landing",children:[t.jsx(Ri,{}),"         "," ",t.jsx(Ti,{}),"      ",t.jsx(Li,{}),"   ",t.jsx(Ni,{}),"    ",t.jsx(Ai,{}),"         ",t.jsx(Pi,{}),"        ",t.jsx(Bi,{}),"     ",t.jsx(Fi,{}),"     ",t.jsx(Hi,{}),"     "]}),t.jsx($i,{}),a.showBanner&&t.jsx(la,{onAcceptAll:a.acceptAll,onRejectAll:a.rejectAll,onManage:()=>a.setShowManager(!0)}),a.showManager&&t.jsx(ca,{onSave:a.saveCustom,onAcceptAll:a.acceptAll,onClose:()=>a.setShowManager(!1),initialValues:a.consent?.categories}),a.consent&&!a.showBanner&&t.jsx(da,{onClick:()=>a.setShowManager(!0)})]})}function pa(){const a=m.useRef(),[e,o]=m.useState(0),r=m.useRef(!1),n=()=>{r.current||(r.current=!0,a.current&&_.to(a.current,{yPercent:-100,duration:1.1,ease:"power4.inOut",delay:.15,onComplete:()=>{a.current&&(a.current.style.display="none")}}))};return m.useEffect(()=>{let i=null;const l=1600,s=h=>{i||(i=h);const f=Math.min((h-i)/l*100,100);o(Math.round(f)),f<100?requestAnimationFrame(s):n()};requestAnimationFrame(s);const d=setTimeout(n,4e3);return()=>clearTimeout(d)},[]),t.jsxs("div",{ref:a,style:{position:"fixed",inset:0,zIndex:9999,background:"#f7fcf7",display:"flex",flexDirection:"column",alignItems:"center",justifyContent:"center",gap:"1.5rem"},children:[t.jsxs("svg",{width:"52",height:"52",viewBox:"0 0 64 64",fill:"none",style:{animation:"nxPulse 1.8s ease-in-out infinite"},"aria-label":"NEXO",children:[t.jsx("rect",{x:"2",y:"2",width:"60",height:"60",rx:"12",stroke:"#2d6e30",strokeWidth:"2"}),t.jsx("path",{d:"M14 50L32 14L50 50",stroke:"#2d6e30",strokeWidth:"3",strokeLinecap:"round",strokeLinejoin:"round"}),t.jsx("path",{d:"M20 38H44",stroke:"#2d6e30",strokeWidth:"2",strokeLinecap:"round"})]}),t.jsx("div",{style:{width:"96px",height:"1px",background:"rgba(200, 230, 200, 0.6)",borderRadius:"1px",overflow:"hidden"},children:t.jsx("div",{style:{height:"100%",background:"linear-gradient(90deg, #2d6e30, rgba(45, 110, 48, 0.45))",borderRadius:"1px",width:`${e}%`,transition:"width 0.1s linear",boxShadow:"0 0 8px rgba(45, 110, 48, 0.5)"}})}),t.jsxs("span",{style:{fontFamily:"'Plus Jakarta Sans', sans-serif",fontSize:"0.62rem",letterSpacing:"0.18em",color:"#4a6e4c",textTransform:"uppercase"},children:[e,"%"]}),t.jsx("style",{children:`
        @keyframes nxPulse {
          0%, 100% { opacity: 1;   transform: scale(1); }
          50%       { opacity: 0.45; transform: scale(0.92); }
        }
      `})]})}class fa extends xo.Component{constructor(e){super(e),this.state={hasError:!1,error:null}}static getDerivedStateFromError(e){return{hasError:!0,error:e}}componentDidCatch(e,o){console.error("ErrorBoundary caught an error:",e,o)}render(){return this.state.hasError?t.jsxs("div",{style:{position:"fixed",inset:0,zIndex:99999,background:"#0a0f0d",color:"#ff5252",padding:"2rem",fontFamily:"monospace",display:"flex",flexDirection:"column",gap:"1rem",overflow:"auto"},children:[t.jsx("h2",{style:{color:"#ff5252",margin:0},children:"⚠️ NEXO Error Boundary"}),t.jsx("p",{style:{color:"#fff",fontSize:"1rem"},children:"El sitio experimentó un error al renderizar:"}),t.jsx("pre",{style:{background:"#111614",padding:"1rem",borderRadius:"4px",border:"1px solid #ff5252",color:"#ff8a80",whiteSpace:"pre-wrap"},children:this.state.error?.toString()}),t.jsx("p",{style:{color:"#8da898",fontSize:"0.85rem"},children:"Revisa la consola del navegador para más detalles."})]}):this.props.children}}function ma(){return t.jsx(fa,{children:t.jsxs(m.Suspense,{fallback:null,children:[t.jsx(pa,{}),t.jsx(ua,{})]})})}Zo.createRoot(document.getElementById("root")).render(t.jsx(xo.StrictMode,{children:t.jsx(ma,{})}));
