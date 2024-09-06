/*! For license information please see index.js.LICENSE.txt */
(()=>{"use strict";var e={792:(e,t,n)=>{n.d(t,{A:()=>s});var r=n(790),i=n(609);const o={isEligible:!0};function s(e){const t=(t,n={})=>{const r={...o,...n},[,s]=(0,i.useReducer)((e=>e+1),0),a=(0,i.useRef)(t);if((0,i.useEffect)((()=>{let n=!0;if(r.isEligible)e.loadExperimentAssignment(t).then((()=>{if(n)s()}));return()=>{n=!1}}),[t,r.isEligible]),t!==a.current&&!a.current.startsWith("explat_test"))e.config.logError({message:"[ExPlat] useExperiment: experimentName should never change between renders!"});if(!r.isEligible)return[!1,null];const m=e.dangerouslyGetMaybeLoadedExperimentAssignment(t);return[!m,m]};return{useExperiment:t,Experiment:({defaultExperience:e,treatmentExperience:n,loadingExperience:i,name:o,options:s})=>{const[a,m]=t(o,s);if(a)return(0,r.jsx)(r.Fragment,{children:i});else if(!m?.variationName)return(0,r.jsx)(r.Fragment,{children:e});return(0,r.jsx)(r.Fragment,{children:n})},ProvideExperimentData:({children:e,name:n,options:r})=>{const[i,o]=t(n,r);return e(i,o)}}}},517:(e,t,n)=>{n.d(t,{kU:()=>c,pg:()=>l});var r=n(689),i=n(808),o=n(738),s=n(762),a=n(626);const m=1e4;Error;function c(e){if("undefined"==typeof window)throw new Error("Running outside of a browser context.");const t={},n=(...t)=>{try{e.logError(...t)}catch(e){}};try{(0,r.bZ)()}catch(e){n({message:e.message,source:"removeExpiredExperimentAssignments-error"})}return{loadExperimentAssignment:async c=>{try{if(!a.Eo(c))throw new Error(`Invalid experimentName: "${c}"`);const n=(0,r.B1)(c);if(n&&i.H2(n))return n;if(void 0===t[c])t[c]=(t=>s.MC((async()=>{const n=await o.FI(e,t);return(0,r.a2)(n),n})))(c);let l=m;if(Math.random()>.5)l=5e3;const p=await s.BK(t[c](),l);if(!p)throw new Error("Could not fetch ExperimentAssignment");return p}catch(e){n({message:e.message,experimentName:c,source:"loadExperimentAssignment-initialError"})}try{const e=(0,r.B1)(c);if(e)return e;const t=(0,i.fj)(c);return(0,r.a2)(t),t}catch(e){return n({message:e.message,experimentName:c,source:"loadExperimentAssignment-fallbackError"}),(0,i.fj)(c)}},dangerouslyGetExperimentAssignment:t=>{try{if(!a.Eo(t))throw new Error(`Invalid experimentName: ${t}`);const i=(0,r.B1)(t);if(!i)throw new Error("Trying to dangerously get an ExperimentAssignment that hasn't loaded.");if(e.isDevelopmentMode)if(i&&s.XZ()-i.retrievedTimestamp<1e3)n({message:"Warning: Trying to dangerously get an ExperimentAssignment too soon after loading it.",experimentName:t,source:"dangerouslyGetExperimentAssignment"});return i}catch(r){if(e.isDevelopmentMode)n({message:r.message,experimentName:t,source:"dangerouslyGetExperimentAssignment-error"});return(0,i.fj)(t)}},dangerouslyGetMaybeLoadedExperimentAssignment:t=>{try{if(!a.Eo(t))throw new Error(`Invalid experimentName: ${t}`);const e=(0,r.B1)(t);if(!e)return null;else return e}catch(r){if(e.isDevelopmentMode)n({message:r.message,experimentName:t,source:"dangerouslyGetMaybeLoadedExperimentAssignment-error"});return(0,i.fj)(t)}},config:e}}function l(e){return{loadExperimentAssignment:async t=>(e.logError({message:"Attempting to load ExperimentAssignment in SSR context",experimentName:t}),(0,i.fj)(t)),dangerouslyGetExperimentAssignment:t=>(e.logError({message:"Attempting to dangerously get ExperimentAssignment in SSR context",experimentName:t}),(0,i.fj)(t)),dangerouslyGetMaybeLoadedExperimentAssignment:t=>(e.logError({message:"Attempting to dangerously get ExperimentAssignment in SSR context",experimentName:t}),(0,i.fj)(t)),config:e}}},226:(e,t,n)=>{n.d(t,{k:()=>i});var r=n(517);const i="undefined"==typeof window?r.pg:r.kU},689:(e,t,n)=>{n.d(t,{B1:()=>c,a2:()=>m,bZ:()=>d});var r=n(808),i=n(765),o=n(626);const s="explat-experiment-",a=e=>`${s}-${e}`;function m(e){o.zV(e);const t=c(e.experimentName);if(t&&e.retrievedTimestamp<t.retrievedTimestamp)throw new Error("Trying to store an older experiment assignment than is present in the store, likely a race condition.");i.A.setItem(a(e.experimentName),JSON.stringify(e))}function c(e){const t=i.A.getItem(a(e));if(t)return o.zV(JSON.parse(t))}const l=e=>[...Array(e).keys()];function p(e){return e.startsWith(s)}function u(e){return e.slice(s.length+1)}function d(){l(i.A.length).map((e=>i.A.key(e))).filter(p).map(u).filter((e=>{try{if(r.H2(c(e)))return!1}catch(e){}return!0})).map(a).map((e=>i.A.removeItem(e)))}},808:(e,t,n)=>{n.d(t,{H2:()=>i,fj:()=>s,fn:()=>o});var r=n(762);function i(e){return r.XZ()<e.ttl*r.If+e.retrievedTimestamp}const o=60,s=(e,t=o)=>({experimentName:e,variationName:null,retrievedTimestamp:r.XZ(),ttl:Math.max(o,t),isFallbackExperimentAssignment:!0})},765:(e,t,n)=>{n.d(t,{A:()=>i});let r={_data:{},setItem:function(e,t){this._data[e]=t},getItem:function(e){return this._data.hasOwnProperty(e)?this._data[e]:null},removeItem:function(e){delete this._data[e]},clear:function(){this._data={}},get length(){return Object.keys(this._data).length},key:function(e){const t=Object.keys(this._data);return e in t?t[e]:null}};try{if(window.localStorage)r=window.localStorage}catch(e){}const i=r},738:(e,t,n)=>{n.d(t,{FI:()=>p});var r=n(808),i=n(765),o=n(762),s=n(626);function a(e){if(function(e){return(0,s.Gv)(e)&&(0,s.Gv)(e.variations)&&"number"==typeof e.ttl&&0<e.ttl}(e))return e;throw new Error("Invalid FetchExperimentAssignmentResponse")}const m="explat-last-anon-id",c="explat-last-anon-id-retrieval-time",l=async e=>{const t=await e();if(t)return i.A.setItem(m,t),i.A.setItem(c,String((0,o.XZ)())),t;const n=i.A.getItem(m),r=i.A.getItem(c);if(n&&r&&(0,o.XZ)()-parseInt(r,10)<864e5)return n;else return null};async function p(e,t){const n=(0,o.XZ)(),{variations:i,ttl:m}=a(await e.fetchExperimentAssignment({anonId:await l(e.getAnonId),experimentName:t})),c=Math.max(r.fn,m),p=Object.entries(i).map((([e,t])=>({experimentName:e,variationName:t,retrievedTimestamp:n,ttl:c}))).map(s.zV);if(p.length>1)throw new Error("Received multiple experiment assignments while trying to fetch exactly one.");if(0===p.length)return r.fj(t,c);const u=p[0];if(u.experimentName!==t)throw new Error("Newly fetched ExperimentAssignment's experiment name does not match request.");if(!r.H2(u))throw new Error("Newly fetched experiment isn't alive.");return u}},762:(e,t,n)=>{n.d(t,{BK:()=>s,If:()=>r,MC:()=>a,XZ:()=>o});const r=1e3;let i=Date.now();function o(){const e=Date.now();return i=i<e?e:i+1,i}function s(e,t){return Promise.race([e,new Promise(((e,n)=>setTimeout((()=>n(new Error(`Promise has timed-out after ${t}ms.`))),t)))])}function a(e){let t=null;return()=>{if(!t)t=e().finally((()=>{t=null}));return t}}},626:(e,t,n)=>{function r(e){return"object"==typeof e&&null!==e}function i(e){return"string"==typeof e&&""!==e&&/^[a-z0-9_]*$/.test(e)}function o(e){if(!function(e){return r(e)&&i(e.experimentName)&&(i(e.variationName)||null===e.variationName)&&"number"==typeof e.retrievedTimestamp&&"number"==typeof e.ttl&&0!==e.ttl}(e))throw new Error("Invalid ExperimentAssignment");return e}n.d(t,{Eo:()=>i,Gv:()=>r,zV:()=>o})},767:(e,t)=>{t.parse=function(e,t){if("string"!=typeof e)throw new TypeError("argument str must be a string");for(var r={},o=t||{},a=e.split(i),m=o.decode||n,c=0;c<a.length;c++){var l=a[c],p=l.indexOf("=");if(!(p<0)){var u=l.substr(0,p).trim(),d=l.substr(++p,l.length).trim();if('"'==d[0])d=d.slice(1,-1);if(null==r[u])r[u]=s(d,m)}}return r},t.serialize=function(e,t,n){var i=n||{},s=i.encode||r;if("function"!=typeof s)throw new TypeError("option encode is invalid");if(!o.test(e))throw new TypeError("argument name is invalid");var a=s(t);if(a&&!o.test(a))throw new TypeError("argument val is invalid");var m=e+"="+a;if(null!=i.maxAge){var c=i.maxAge-0;if(isNaN(c)||!isFinite(c))throw new TypeError("option maxAge is invalid");m+="; Max-Age="+Math.floor(c)}if(i.domain){if(!o.test(i.domain))throw new TypeError("option domain is invalid");m+="; Domain="+i.domain}if(i.path){if(!o.test(i.path))throw new TypeError("option path is invalid");m+="; Path="+i.path}if(i.expires){if("function"!=typeof i.expires.toUTCString)throw new TypeError("option expires is invalid");m+="; Expires="+i.expires.toUTCString()}if(i.httpOnly)m+="; HttpOnly";if(i.secure)m+="; Secure";if(i.sameSite){switch("string"==typeof i.sameSite?i.sameSite.toLowerCase():i.sameSite){case!0:m+="; SameSite=Strict";break;case"lax":m+="; SameSite=Lax";break;case"strict":m+="; SameSite=Strict";break;case"none":m+="; SameSite=None";break;default:throw new TypeError("option sameSite is invalid")}}return m};var n=decodeURIComponent,r=encodeURIComponent,i=/; */,o=/^[\u0009\u0020-\u007e\u0080-\u00ff]+$/;function s(e,t){try{return t(e)}catch(t){return e}}},889:(e,t,n)=>{n.d(t,{Ck:()=>s,wf:()=>o});var r=n(767);let i=null;const o=async()=>{let e=0;return i=new Promise((t=>{const n=()=>{const i=r.parse(document.cookie).tk_ai||null;if("string"!=typeof i||""===i)if(!(99<=e))e+=1,setTimeout(n,50);else t(null);else t(i)};n()})),i},s=async()=>await i},222:(e,t,n)=>{n.d(t,{V:()=>m,z:()=>a});var r=n(455),i=n.n(r),o=n(832);const s=(e=!1)=>async({experimentName:t,anonId:n})=>{if(!n)throw new Error("Tracking is disabled, can't fetch experimentAssignment");const r={experiment_name:t,anon_id:n??void 0,as_connected_user:e},s=(0,o.addQueryArgs)("jetpack/v4/explat/assignments",r);return await i()({path:s})},a=s(!1),m=s(!0)},69:(e,t,n)=>{n.d(t,{v:()=>i});var r=n(382);const i=e=>{const t=e=>{if(r.D)console.error("[ExPlat] Unable to send error to server:",e)};try{const{message:n,...i}=e,o={message:n,properties:{...i,context:"explat",explat_client:"jetpack"}};if(r.D)console.error("[ExPlat] ",e.message,e);else{const e=new window.FormData;e.append("error",JSON.stringify(o)),window.fetch("https://public-api.wordpress.com/rest/v1.1/js-error",{method:"POST",body:e}).catch(t)}}catch(e){t(e)}}},382:(e,t,n)=>{n.d(t,{D:()=>r});const r=!1},609:e=>{e.exports=window.React},790:e=>{e.exports=window.ReactJSXRuntime},455:e=>{e.exports=window.wp.apiFetch},832:e=>{e.exports=window.wp.url}},t={};function n(r){var i=t[r];if(void 0!==i)return i.exports;var o=t[r]={exports:{}};return e[r](o,o.exports,n),o.exports}n.n=e=>{var t=e&&e.__esModule?()=>e.default:()=>e;return n.d(t,{a:t}),t},n.d=(e,t)=>{for(var r in t)if(n.o(t,r)&&!n.o(e,r))Object.defineProperty(e,r,{enumerable:!0,get:t[r]})},n.o=(e,t)=>Object.prototype.hasOwnProperty.call(e,t);var r=n(226),i=n(792),o=n(889),s=n(222),a=n(69),m=n(382);(0,o.wf)().catch((e=>(0,a.v)({message:e.message})));const c=(0,r.k)({fetchExperimentAssignment:s.z,getAnonId:o.Ck,logError:a.v,isDevelopmentMode:m.D}),{loadExperimentAssignment:l,dangerouslyGetExperimentAssignment:p}=c,{useExperiment:u,Experiment:d,ProvideExperimentData:f}=(0,i.A)(c),g=(0,r.k)({fetchExperimentAssignment:s.V,getAnonId:o.Ck,logError:a.v,isDevelopmentMode:m.D}),{loadExperimentAssignment:x,dangerouslyGetExperimentAssignment:h}=g,{useExperiment:w,Experiment:E,ProvideExperimentData:y}=(0,i.A)(g)})();