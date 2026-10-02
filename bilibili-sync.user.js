// ==UserScript==
// @name         哔哩哔哩进度同步
// @namespace    https://github.com/pipidu/watogetbili
// @version      1.0.0
// @description  创建房间，多人同步哔哩哔哩视频和播放进度。默认同步房主，房主可授权成员调整进度或更换视频。
// @match        https://www.bilibili.com/video/*
// @match        https://www.bilibili.com/bangumi/play/*
// @match        https://m.bilibili.com/video/*
// @match        https://m.bilibili.com/bangumi/play/*
// @grant        GM_getValue
// @grant        GM_setValue
// @grant        GM_xmlhttpRequest
// @grant        GM_addStyle
// @connect      *
// @run-at       document-idle
// ==/UserScript==

(function () {
  'use strict';

  if (typeof GM_getValue !== 'function' || typeof GM_xmlhttpRequest !== 'function') {
    console.error('[bili-sync] 请在 Tampermonkey 或 Violentmonkey 中安装这个脚本');
    return;
  }

  const DRIFT_PLAYING = 1.15;
  const DRIFT_PAUSED = 0.35;

  let session = null;
  let lastRoom = null;
  let you = null;
  let pending = null;
  let chain = Promise.resolve();
  let loopGen = 0;
  let clockOffset = 0;
  let clockReady = false;
  let optimisticUntil = 0;
  let suppressUntil = 0;
  let lastHeartbeat = 0;
  let lastSeekAt = 0;
  let lastPlayKick = 0;
  let navigating = false;
  let pushedStartup = false;
  let askedEmptyVideo = false;
  let lastRoomSig = '';
  let root = null;

  const bootFollow = (() => {
    try {
      const flag = sessionStorage.getItem('biliSyncFollow') === '1';
      sessionStorage.removeItem('biliSyncFollow');
      return flag;
    } catch (err) {
      return false;
    }
  })();

  // <canonical>
  function canonicalize(url, title) {
    let parsed;
    try {
      parsed = new URL(url);
    } catch (err) {
      return null;
    }
    if (parsed.protocol !== 'https:') return null;
    const host = parsed.hostname.toLowerCase();
    if (host !== 'www.bilibili.com' && host !== 'bilibili.com' && host !== 'm.bilibili.com') return null;
    const clean = cleanTitle(title);
    let match = parsed.pathname.match(/^\/video\/(BV[0-9A-Za-z]+|av\d+)\/?$/i);
    if (match) {
      let id = match[1];
      if (/^av/i.test(id)) id = 'av' + id.slice(2);
      let page = parseInt(parsed.searchParams.get('p') || '1', 10);
      if (!Number.isFinite(page) || page < 1) page = 1;
      if (page > 9999) page = 9999;
      return {
        key: id + '#p' + page,
        url: 'https://www.bilibili.com/video/' + id + '/?p=' + page,
        title: clean,
      };
    }
    match = parsed.pathname.match(/^\/bangumi\/play\/(ep\d+|ss\d+)\/?$/i);
    if (match) {
      const id = match[1].toLowerCase();
      if (id.startsWith('ss')) {
        const ep = parsed.searchParams.get('ep') || '';
        if (/^\d{1,12}$/.test(ep)) {
          return {
            key: id + '#ep' + ep,
            url: 'https://www.bilibili.com/bangumi/play/' + id + '?ep=' + ep,
            title: clean,
          };
        }
      }
      return {
        key: id,
        url: 'https://www.bilibili.com/bangumi/play/' + id,
        title: clean,
      };
    }
    return null;
  }

  function cleanTitle(title) {
    const text = String(title || '').replace(/[\u0000-\u001F\u007F]/g, '').trim();
    const chars = Array.from(text);
    return chars.length > 80 ? chars.slice(0, 80).join('') : text;
  }
  // </canonical>

  function pageTitle() {
    return String(document.title || '').replace(/\s*[-_｜|].*哔哩哔哩[\s\S]*$/, '').trim();
  }

  function pageIdentity() {
    return canonicalize(location.href, pageTitle());
  }

  function localizeUrl(url) {
    const target = new URL(url);
    target.hostname = location.hostname === 'm.bilibili.com' ? 'm.bilibili.com' : 'www.bilibili.com';
    return target.toString();
  }

  function normalizeBackend(input) {
    const raw = String(input || '').trim();
    if (!raw) throw new Error('请先填写后端地址');
    let url;
    try {
      url = new URL(raw);
    } catch (err) {
      throw new Error('后端地址不是有效的链接');
    }
    if (url.protocol !== 'http:' && url.protocol !== 'https:') {
      throw new Error('后端地址只支持 http 或 https');
    }
    url.search = '';
    url.hash = '';
    return url.toString().replace(/\/$/, '');
  }

  function storedBackend() {
    return String(GM_getValue('biliSync.backend', '') || '');
  }

  function nickname() {
    const input = document.getElementById('bili-sync-nick');
    const value = (input ? input.value : String(GM_getValue('biliSync.nick', '') || '')).trim();
    return Array.from(value).slice(0, 16).join('') || '用户';
  }

  function backendFromInput() {
    const input = document.getElementById('bili-sync-backend');
    return normalizeBackend(input ? input.value : storedBackend());
  }

  function loadSession() {
    const raw = GM_getValue('biliSync.session', null);
    if (raw == null || raw === '') return null;
    const data = typeof raw === 'object' ? raw : JSON.parse(String(raw));
    if (!data || !data.roomId || !data.memberId || !data.token || !data.backend) return null;
    return data;
  }

  function persistSession() {
    if (!session) {
      GM_setValue('biliSync.session', '');
      return;
    }
    GM_setValue('biliSync.session', JSON.stringify(session));
  }

  function clearSession() {
    session = null;
    lastRoom = null;
    you = null;
    pending = null;
    lastRoomSig = '';
    optimisticUntil = 0;
    persistSession();
    stopLoop();
  }

  function api(action, payload, method) {
    const base = backendFromInput();
    return new Promise((resolve, reject) => {
      GM_xmlhttpRequest({
        method: method || 'POST',
        url: base + '?action=' + encodeURIComponent(action),
        data: method === 'GET' ? undefined : JSON.stringify(payload || {}),
        headers: method === 'GET' ? {} : { 'Content-Type': 'application/json' },
        timeout: 12000,
        onload(res) {
          let data = null;
          try {
            data = JSON.parse(res.responseText || '{}');
          } catch (err) {
            reject(new Error('后端返回的不是 JSON'));
            return;
          }
          if (res.status >= 400 || data.ok === false) {
            const error = new Error(data.error || ('请求失败 ' + res.status));
            error.status = res.status;
            reject(error);
            return;
          }
          resolve(data);
        },
        onerror() {
          reject(new Error('无法连接后端'));
        },
        ontimeout() {
          reject(new Error('后端超时'));
        },
      });
    });
  }

  function enqueue(task) {
    const run = chain.then(task, task);
    chain = run.then(() => {}, () => {});
    return run;
  }

  function sleep(ms) {
    return new Promise((resolve) => setTimeout(resolve, ms));
  }

  function authBody() {
    return {
      roomId: session.roomId,
      memberId: session.memberId,
      token: session.token,
      name: nickname(),
    };
  }

  function capturePlayback() {
    const video = getVideo();
    if (!video) return { time: 0, playing: false, rate: 1 };
    const time = Number.isFinite(video.currentTime) ? Math.round(video.currentTime * 1000) / 1000 : 0;
    return {
      time: time,
      playing: !video.paused && !video.ended,
      rate: Number.isFinite(video.playbackRate) ? video.playbackRate : 1,
    };
  }

  function shouldHeartbeat() {
    if (!lastRoom || !you || !lastRoom.playback) return false;
    if (lastRoom.playback.driverId !== you.id) return false;
    if (Date.now() - lastHeartbeat < 2000) return false;
    return !!getVideo();
  }

  async function syncNow() {
    if (!session) return;
    const body = authBody();
    const extra = pending;
    pending = null;
    if (extra) {
      Object.assign(body, extra);
    } else if (shouldHeartbeat()) {
      body.mode = 'heartbeat';
      body.playback = capturePlayback();
      lastHeartbeat = Date.now();
    }
    const res = await api('sync', body);
    if (res.left) {
      clearSession();
      setStatus('已离开房间', 'ok');
      paint();
      return;
    }
    absorb(res);
  }

  function handleSyncError(err) {
    const status = err && err.status;
    if (status === 401 || status === 404) {
      clearSession();
      setStatus(err.message || '房间已失效', 'err');
      paint();
      return;
    }
    if (status === 403) {
      optimisticUntil = 0;
      setStatus(err.message || '没有权限', 'err');
      if (lastRoom) applyPlayback(lastRoom);
      return;
    }
    setStatus((err && err.message) || '同步失败', 'err');
  }

  function startLoop() {
    const gen = ++loopGen;
    (async () => {
      while (gen === loopGen && session) {
        try {
          await enqueue(() => syncNow());
        } catch (err) {
          handleSyncError(err);
        }
        await sleep(700);
      }
    })();
  }

  function stopLoop() {
    loopGen++;
  }

  function noteServerNow(serverNow) {
    if (typeof serverNow !== 'number') return;
    const sample = serverNow - Date.now();
    clockOffset = clockReady ? clockOffset * 0.7 + sample * 0.3 : sample;
    clockReady = true;
  }

  function estimatedServerNow() {
    return Date.now() + (clockReady ? clockOffset : 0);
  }

  function expectedTime(playback) {
    if (!playback) return 0;
    const rate = playback.rate || 1;
    if (!playback.playing) return playback.time || 0;
    const age = Math.max(0, estimatedServerNow() - (playback.updatedAt || 0));
    return (playback.time || 0) + (Math.min(age, 8000) / 1000) * rate;
  }

  function canControl() {
    return !!(you && (you.isHost || you.canControl));
  }

  function canChangeVideo() {
    return !!(you && (you.isHost || you.canChangeVideo));
  }

  function isDriver() {
    if (Date.now() < optimisticUntil) return true;
    return !!(lastRoom && you && lastRoom.playback && lastRoom.playback.driverId === you.id);
  }

  function getVideo() {
    const list = Array.from(document.querySelectorAll('video'));
    document.querySelectorAll('iframe').forEach((frame) => {
      try {
        if (frame.contentDocument) {
          frame.contentDocument.querySelectorAll('video').forEach((video) => list.push(video));
        }
      } catch (err) {
        /* 跨域播放器读不到 */
      }
    });
    let best = null;
    let area = -1;
    list.forEach((video) => {
      const rect = video.getBoundingClientRect();
      const size = Math.max(0, rect.width) * Math.max(0, rect.height);
      const ready = video.readyState || 0;
      if (size > area || (size === area && best && ready > best.readyState)) {
        area = size;
        best = video;
      }
    });
    return best;
  }

  function canSeekTo(video, time) {
    const ranges = video.seekable;
    if (!ranges) return false;
    for (let i = 0; i < ranges.length; i++) {
      if (time >= ranges.start(i) && time <= ranges.end(i) + 0.05) return true;
    }
    return false;
  }

  function capTime(video, time) {
    let next = Math.max(0, time);
    if (Number.isFinite(video.duration) && video.duration > 1) {
      next = Math.min(next, Math.max(0, video.duration - 0.25));
    }
    return next;
  }

  function applyPlayback(room) {
    if (!room || !room.playback || isDriver()) return;
    const video = getVideo();
    const playback = room.playback;
    if (!video || video.readyState < 1) return;
    const target = capTime(video, expectedTime(playback));
    const threshold = playback.playing ? DRIFT_PLAYING : DRIFT_PAUSED;
    const rate = playback.rate || 1;
    const drift = Math.abs(video.currentTime - target);
    const rateDiff = Math.abs((video.playbackRate || 1) - rate) > 0.01;
    const playDiff = playback.playing ? video.paused : !video.paused;
    const shouldSeek = drift > threshold && canSeekTo(video, target) && Date.now() - lastSeekAt > 800;
    const shouldPlay = playback.playing && video.paused && Date.now() - lastPlayKick > 1500;
    const shouldPause = !playback.playing && !video.paused;
    if (!rateDiff && !shouldSeek && !shouldPlay && !shouldPause) return;
    suppressUntil = Date.now() + 700;
    if (rateDiff) video.playbackRate = rate;
    if (shouldSeek) {
      lastSeekAt = Date.now();
      try {
        video.currentTime = target;
      } catch (err) {
        /* 播放器还不能拖动时，下一轮再试 */
      }
    }
    if (shouldPlay) {
      lastPlayKick = Date.now();
      const pendingPlay = video.play();
      if (pendingPlay && typeof pendingPlay.catch === 'function') {
        pendingPlay.catch(() => showNeedGesture());
      }
    } else if (shouldPause) {
      video.pause();
    }
  }

  function showNeedGesture() {
    const button = document.getElementById('bili-sync-gesture');
    if (button) button.hidden = false;
  }

  function queuePlayback() {
    optimisticUntil = Date.now() + 2500;
    lastHeartbeat = Date.now();
    pending = { mode: 'user', playback: capturePlayback() };
    enqueue(() => syncNow()).catch(handleSyncError);
  }

  function queueVideo(identity, playback) {
    if (!identity) return;
    optimisticUntil = Date.now() + 2500;
    lastHeartbeat = Date.now();
    pending = {
      mode: 'user',
      video: { url: identity.url, title: identity.title || '', key: identity.key },
      playback: playback || { time: 0, playing: false, rate: capturePlayback().rate || 1 },
    };
    enqueue(() => syncNow()).catch(handleSyncError);
  }

  function onMedia() {
    if (Date.now() < suppressUntil) return;
    if (!session || !lastRoom) return;
    if (!canControl()) {
      applyPlayback(lastRoom);
      return;
    }
    queuePlayback();
  }

  const boundVideos = new WeakSet();
  function bindVideo(video) {
    if (!video || boundVideos.has(video)) return;
    boundVideos.add(video);
    ['play', 'pause', 'seeked', 'ratechange'].forEach((name) => video.addEventListener(name, onMedia));
    video.addEventListener('loadedmetadata', () => {
      if (lastRoom) applyPlayback(lastRoom);
    });
  }

  function followNav(url) {
    if (!url || navigating) return;
    const next = localizeUrl(url);
    const local = pageIdentity();
    const target = canonicalize(next, '');
    if (local && target && local.key === target.key) return;
    navigating = true;
    try {
      sessionStorage.setItem('biliSyncFollow', '1');
    } catch (err) {
      /* 隐私模式可能禁掉 sessionStorage */
    }
    location.assign(next);
  }

  function reconcileVideo(room) {
    const local = pageIdentity();
    const remote = room && room.video;
    if (!local || !remote) return;
    if (!remote.key) {
      if (canChangeVideo() && !askedEmptyVideo) {
        askedEmptyVideo = true;
        queueVideo(local, capturePlayback());
      }
      return;
    }
    if (local.key === remote.key) return;
    if (!bootFollow && canChangeVideo() && !pushedStartup) {
      pushedStartup = true;
      queueVideo(local, capturePlayback());
      setStatus('已用当前页面更新房间视频', 'ok');
      return;
    }
    followNav(remote.url);
  }

  let lastKey = (pageIdentity() || {}).key || '';
  function checkUrl() {
    const identity = pageIdentity();
    const key = identity ? identity.key : '';
    if (key === lastKey) return;
    lastKey = key;
    if (!session || !lastRoom || !identity || navigating) return;
    if (lastRoom.video && lastRoom.video.key === key) return;
    if (!canChangeVideo()) {
      if (lastRoom.video && lastRoom.video.url) followNav(lastRoom.video.url);
      return;
    }
    queueVideo(identity);
  }

  function bindHistory() {
    const wrap = (original) => function () {
      const result = original.apply(this, arguments);
      setTimeout(checkUrl, 60);
      return result;
    };
    if (history.pushState && !history.pushState.__biliSync) {
      const push = wrap(history.pushState);
      push.__biliSync = true;
      history.pushState = push;
      history.replaceState = wrap(history.replaceState);
    }
    window.addEventListener('popstate', () => setTimeout(checkUrl, 60));
  }

  function absorb(res) {
    noteServerNow(res.serverNow);
    lastRoom = res.room;
    you = res.you;
    paint();
    reconcileVideo(res.room);
    applyPlayback(res.room);
  }

  function saveSessionFrom(res) {
    session = {
      backend: backendFromInput(),
      roomId: res.room.id,
      memberId: res.you.id,
      token: res.token,
    };
    persistSession();
    absorb(res);
    startLoop();
  }

  async function createRoom() {
    const identity = pageIdentity();
    if (!identity) throw new Error('请在视频或番剧播放页使用');
    const res = await api('create', {
      name: nickname(),
      video: identity,
      playback: capturePlayback(),
    });
    saveSessionFrom(res);
    setStatus('房间已创建，把房号发给朋友', 'ok');
  }

  async function joinRoom() {
    const input = document.getElementById('bili-sync-code');
    const roomId = (input ? input.value : '').trim().toUpperCase();
    const res = await api('join', { roomId: roomId, name: nickname() });
    saveSessionFrom(res);
    setStatus('已加入房间', 'ok');
  }

  async function leaveRoom() {
    const current = session;
    stopLoop();
    if (current) {
      try {
        await api('leave', {
          roomId: current.roomId,
          memberId: current.memberId,
          token: current.token,
        });
      } catch (err) {
        /* 房间可能已经没了，本地照样退出 */
      }
    }
    clearSession();
    paint();
    setStatus('已离开房间', 'ok');
  }

  function sendPermit(payload) {
    if (!session) return;
    enqueue(async () => {
      const res = await api('permit', Object.assign(authBody(), payload));
      absorb(res);
    }).catch(handleSyncError);
  }

  function sendTransfer(targetId) {
    if (!session) return;
    enqueue(async () => {
      const res = await api('transfer', Object.assign(authBody(), { targetId: targetId }));
      absorb(res);
      setStatus('已移交房主', 'ok');
    }).catch(handleSyncError);
  }

  function sendKick(targetId) {
    if (!session) return;
    enqueue(async () => {
      const res = await api('kick', Object.assign(authBody(), { targetId: targetId }));
      absorb(res);
    }).catch(handleSyncError);
  }

  function h(tag, attrs, children) {
    const node = document.createElement(tag);
    Object.keys(attrs || {}).forEach((key) => {
      const value = attrs[key];
      if (key === 'class') node.className = value;
      else if (key === 'text') node.textContent = value == null ? '' : String(value);
      else if (key === 'hidden') node.hidden = !!value;
      else if (key.slice(0, 2) === 'on' && typeof value === 'function') node.addEventListener(key.slice(2), value);
      else if (value != null) node.setAttribute(key, String(value));
    });
    [].concat(children || []).forEach((child) => {
      if (child == null || child === false) return;
      node.appendChild(typeof child === 'string' ? document.createTextNode(child) : child);
    });
    return node;
  }

  function setStatus(text, level) {
    const el = document.getElementById('bili-sync-status');
    const dot = document.getElementById('bili-sync-dot');
    if (el) el.textContent = text;
    if (dot) dot.className = 'dot ' + (level || 'warn');
  }

  function memberName(id) {
    const members = (lastRoom && lastRoom.members) || [];
    const found = members.find((member) => member.id === id);
    return found ? found.name : '房主';
  }

  function roomSig() {
    if (!lastRoom || !you) return '';
    return JSON.stringify({
      id: lastRoom.id,
      you: you,
      video: lastRoom.video,
      driver: lastRoom.playback && lastRoom.playback.driverId,
      allowC: lastRoom.allowAllControl,
      allowV: lastRoom.allowAllVideo,
      members: lastRoom.members,
    });
  }

  function paintDrift() {
    const el = document.getElementById('bili-sync-drift');
    if (!el) return;
    if (!session || !lastRoom || !lastRoom.playback) {
      el.textContent = '';
      return;
    }
    const video = getVideo();
    const driver = memberName(lastRoom.playback.driverId);
    if (!video) {
      el.textContent = '同步源 ' + driver + ' · 还没找到播放器';
      return;
    }
    const drift = video.currentTime - expectedTime(lastRoom.playback);
    el.textContent = '同步源 ' + driver + ' · 本地偏差 ' + (drift >= 0 ? '+' : '') + drift.toFixed(1) + 's';
  }

  function paintRoom() {
    const box = document.getElementById('bili-sync-room');
    if (!box) return;
    const inRoom = !!(session && lastRoom);
    box.hidden = !inRoom;
    const connect = document.getElementById('bili-sync-connect');
    if (connect) connect.hidden = inRoom;
    if (!inRoom) {
      lastRoomSig = '';
      box.replaceChildren();
      return;
    }
    const sig = roomSig();
    if (sig === lastRoomSig) {
      paintDrift();
      return;
    }
    lastRoomSig = sig;
    const video = lastRoom.video || {};
    const amHost = !!(you && you.isHost);
    const members = lastRoom.members || [];
    const list = h('div', { class: 'members' }, members.map((member) => {
      const tags = [];
      if (member.isHost) tags.push('房主');
      if (lastRoom.playback && lastRoom.playback.driverId === member.id && !member.isHost) tags.push('正在控制进度');
      if (!member.isHost && member.canControl) tags.push('可调进度');
      if (!member.isHost && member.canChangeVideo) tags.push('可换视频');
      if (!member.online) tags.push('离线');
      const controls = [];
      if (amHost && member.id !== you.id) {
        controls.push(h('label', { class: 'check' }, [
          h('input', {
            type: 'checkbox',
            checked: member.grantControl ? 'checked' : null,
            onchange: (event) => sendPermit({
              targetId: member.id,
              canControl: event.target.checked,
              canChangeVideo: member.grantVideo,
            }),
          }),
          '单独授权进度',
        ]));
        controls.push(h('label', { class: 'check' }, [
          h('input', {
            type: 'checkbox',
            checked: member.grantVideo ? 'checked' : null,
            onchange: (event) => sendPermit({
              targetId: member.id,
              canControl: member.grantControl,
              canChangeVideo: event.target.checked,
            }),
          }),
          '单独授权换视频',
        ]));
        controls.push(h('button', {
          type: 'button',
          class: 'ghost',
          onclick: () => {
            if (confirm('把房主交给 ' + member.name + '？')) sendTransfer(member.id);
          },
          text: '移交房主',
        }));
        controls.push(h('button', {
          type: 'button',
          class: 'ghost danger',
          onclick: () => {
            if (confirm('把 ' + member.name + ' 移出房间？')) sendKick(member.id);
          },
          text: '移出',
        }));
      }
      return h('div', { class: 'member' }, [
        h('div', { class: 'member-line' }, [
          h('i', { class: member.online ? 'online' : 'offline' }),
          h('b', { text: member.name }),
          h('span', { text: tags.join(' · ') }),
        ]),
        controls.length ? h('div', { class: 'member-actions' }, controls) : null,
      ]);
    }));

    const allow = amHost ? h('div', { class: 'allow' }, [
      h('label', { class: 'check' }, [
        h('input', {
          type: 'checkbox',
          checked: lastRoom.allowAllControl ? 'checked' : null,
          onchange: (event) => sendPermit({ allowAllControl: event.target.checked }),
        }),
        '全员可调进度',
      ]),
      h('label', { class: 'check' }, [
        h('input', {
          type: 'checkbox',
          checked: lastRoom.allowAllVideo ? 'checked' : null,
          onchange: (event) => sendPermit({ allowAllVideo: event.target.checked }),
        }),
        '全员可换视频',
      ]),
    ]) : null;

    box.replaceChildren(
      h('div', { class: 'code-line' }, [
        h('strong', { text: lastRoom.id }),
        h('button', {
          type: 'button',
          class: 'ghost',
          onclick: async (event) => {
            const button = event.currentTarget;
            try {
              await navigator.clipboard.writeText(lastRoom.id);
              button.textContent = '已复制';
            } catch (err) {
              button.textContent = '请手动复制';
            }
          },
          text: '复制房号',
        }),
        h('button', { type: 'button', class: 'ghost danger', onclick: () => leaveRoom(), text: '离开' }),
      ]),
      h('div', { class: 'meta', text: amHost ? '你是房主。朋友加入后，默认跟着你的视频和进度。' : (canControl() || canChangeVideo() ? '你已获得授权，操作会同步给房间里的其他人。' : '正在跟随房主。暂停或拖动会被拉回去。') }),
      h('div', { class: 'meta', text: video.title || video.key || '等待房主打开视频' }),
      h('div', { id: 'bili-sync-drift', class: 'meta' }),
      ...(allow ? [allow] : []),
      h('button', {
        id: 'bili-sync-gesture',
        type: 'button',
        hidden: true,
        onclick: () => {
          const videoEl = getVideo();
          if (!videoEl) return;
          const pendingPlay = videoEl.play();
          if (pendingPlay && typeof pendingPlay.then === 'function') {
            pendingPlay.then(() => {
              const button = document.getElementById('bili-sync-gesture');
              if (button) button.hidden = true;
            }).catch(() => {});
          }
        },
        text: '点击这里开始同步播放',
      }),
      h('div', { class: 'meta', text: '成员 ' + members.length }),
      list
    );
    paintDrift();
  }

  function paint() {
    paintRoom();
    const settings = document.getElementById('bili-sync-settings');
    if (settings) settings.open = !session;
  }

  function relocate() {
    if (!root) return;
    const fullscreen = document.fullscreenElement || document.webkitFullscreenElement;
    const parent = fullscreen || document.body;
    if (root.parentElement !== parent) parent.appendChild(root);
  }

  function addStyle(css) {
    if (typeof GM_addStyle === 'function') {
      GM_addStyle(css);
      return;
    }
    const style = document.createElement('style');
    style.textContent = css;
    document.documentElement.appendChild(style);
  }

  function buildPanel() {
    addStyle(`
      #bili-sync-root { position: fixed; top: 72px; right: 16px; z-index: 2147483646; width: 320px; max-width: calc(100vw - 24px); color: #f7f8fa; font: 13px/1.45 "PingFang SC","Microsoft YaHei",sans-serif; }
      #bili-sync-root * { box-sizing: border-box; }
      #bili-sync-root button, #bili-sync-root input { font: inherit; color: inherit; }
      #bili-sync-root .panel { background: rgba(22, 24, 28, .94); border: 1px solid rgba(255,255,255,.08); border-radius: 14px; box-shadow: 0 12px 40px rgba(0,0,0,.35); overflow: hidden; }
      #bili-sync-root header { display: flex; align-items: center; gap: 8px; padding: 10px 12px; cursor: move; user-select: none; background: rgba(255,255,255,.03); }
      #bili-sync-root header b { font-size: 14px; font-weight: 700; }
      #bili-sync-root .dot { width: 8px; height: 8px; border-radius: 99px; background: #f5c451; display: inline-block; }
      #bili-sync-root .dot.ok { background: #3dd68c; }
      #bili-sync-root .dot.err { background: #ff6b6b; }
      #bili-sync-root .dot.warn { background: #f5c451; }
      #bili-sync-root #bili-sync-status { flex: 1; color: #c6ccd6; overflow: hidden; text-overflow: ellipsis; white-space: nowrap; }
      #bili-sync-root .body { padding: 10px 12px 12px; display: flex; flex-direction: column; gap: 8px; max-height: min(70vh, 560px); overflow: auto; }
      #bili-sync-root .body.collapsed { display: none; }
      #bili-sync-root label.field { display: flex; flex-direction: column; gap: 4px; color: #c6ccd6; }
      #bili-sync-root input[type="text"], #bili-sync-root input[type="url"] { width: 100%; border: 1px solid rgba(255,255,255,.12); background: rgba(255,255,255,.04); border-radius: 8px; padding: 7px 8px; outline: none; }
      #bili-sync-root input:focus { border-color: #fb7299; }
      #bili-sync-root .row { display: flex; gap: 6px; }
      #bili-sync-root .row > * { flex: 1; }
      #bili-sync-root button { border: 0; border-radius: 999px; padding: 7px 10px; background: #fb7299; color: white; cursor: pointer; }
      #bili-sync-root button.ghost { background: rgba(255,255,255,.08); color: #f7f8fa; }
      #bili-sync-root button.danger { color: #ffb4b4; }
      #bili-sync-root button:disabled { opacity: .55; cursor: default; }
      #bili-sync-root details { color: #c6ccd6; }
      #bili-sync-root summary { cursor: pointer; margin-bottom: 6px; }
      #bili-sync-root .code-line { display: flex; align-items: center; gap: 6px; }
      #bili-sync-root .code-line strong { font: 700 20px/1 ui-monospace, SFMono-Regular, Menlo, Consolas, monospace; letter-spacing: .12em; }
      #bili-sync-root .meta { color: #c6ccd6; }
      #bili-sync-root .members { display: flex; flex-direction: column; gap: 8px; }
      #bili-sync-root .member { padding-top: 6px; border-top: 1px solid rgba(255,255,255,.08); }
      #bili-sync-root .member-line { display: flex; gap: 6px; align-items: center; }
      #bili-sync-root .member-line span { color: #9aa3b2; }
      #bili-sync-root .member-actions { display: flex; flex-wrap: wrap; gap: 6px; margin-top: 6px; }
      #bili-sync-root .check { display: flex; align-items: center; gap: 4px; color: #d5dbe3; }
      #bili-sync-root .allow { display: flex; gap: 10px; flex-wrap: wrap; }
      #bili-sync-root i.online, #bili-sync-root i.offline { width: 7px; height: 7px; border-radius: 99px; display: inline-block; }
      #bili-sync-root i.online { background: #3dd68c; }
      #bili-sync-root i.offline { background: #667085; }
      #bili-sync-root #bili-sync-gesture { background: #00aeec; }
    `);

    const collapsed = GM_getValue('biliSync.collapsed', '0') === '1';
    const backend = storedBackend();
    const nick = String(GM_getValue('biliSync.nick', '') || '');
    const body = h('div', { id: 'bili-sync-body', class: 'body' + (collapsed ? ' collapsed' : '') });
    body.append(
      h('details', { id: 'bili-sync-settings', open: collapsed ? null : 'open' }, [
        h('summary', { text: '后端和昵称' }),
        h('label', { class: 'field' }, [
          '后端地址',
          h('input', { id: 'bili-sync-backend', type: 'url', placeholder: 'https://example.com/sync.php', value: backend }),
        ]),
        h('label', { class: 'field' }, [
          '昵称',
          h('input', { id: 'bili-sync-nick', type: 'text', maxlength: '16', placeholder: '显示在成员列表里', value: nick }),
        ]),
        h('div', { class: 'row' }, [
          h('button', { type: 'button', class: 'ghost', onclick: () => testBackend(), text: '测试连接' }),
          h('button', { type: 'button', onclick: () => saveSettings(), text: '保存' }),
        ]),
      ]),
      h('div', { id: 'bili-sync-connect' }, [
        h('div', { class: 'row' }, [
          h('button', { type: 'button', onclick: () => runAction(createRoom), text: '创建房间' }),
        ]),
        h('div', { class: 'row' }, [
          h('input', { id: 'bili-sync-code', type: 'text', maxlength: '6', placeholder: '六位房号' }),
          h('button', { type: 'button', onclick: () => runAction(joinRoom), text: '加入' }),
        ]),
      ]),
      h('div', { id: 'bili-sync-room', hidden: true }),
      h('div', { class: 'meta', text: '默认同步房主。房主勾选授权后，该成员的拖动、暂停和换视频会成为新的同步源。' })
    );

    root = h('div', { id: 'bili-sync-root' }, [
      h('div', { class: 'panel' }, [
        h('header', {}, [
          h('i', { id: 'bili-sync-dot', class: 'dot warn' }),
          h('b', { text: '一起看' }),
          h('span', { id: 'bili-sync-status', text: '未连接' }),
          h('button', {
            type: 'button',
            class: 'ghost',
            onclick: () => {
              const box = document.getElementById('bili-sync-body');
              const next = !box.classList.contains('collapsed');
              box.classList.toggle('collapsed', next);
              GM_setValue('biliSync.collapsed', next ? '1' : '0');
            },
            text: '收起',
          }),
        ]),
        body,
      ]),
    ]);

    const pos = (() => {
      try {
        return JSON.parse(GM_getValue('biliSync.pos', '') || 'null');
      } catch (err) {
        return null;
      }
    })();
    if (pos && Number.isFinite(pos.left) && Number.isFinite(pos.top)) {
      root.style.left = pos.left + 'px';
      root.style.top = pos.top + 'px';
      root.style.right = 'auto';
    }
    const header = root.querySelector('header');
    let drag = null;
    header.addEventListener('pointerdown', (event) => {
      if (event.target.closest('button')) return;
      const rect = root.getBoundingClientRect();
      drag = { x: event.clientX, y: event.clientY, left: rect.left, top: rect.top };
      header.setPointerCapture(event.pointerId);
    });
    header.addEventListener('pointermove', (event) => {
      if (!drag) return;
      const left = Math.max(8, Math.min(window.innerWidth - 80, drag.left + event.clientX - drag.x));
      const top = Math.max(8, Math.min(window.innerHeight - 40, drag.top + event.clientY - drag.y));
      root.style.left = left + 'px';
      root.style.top = top + 'px';
      root.style.right = 'auto';
    });
    header.addEventListener('pointerup', () => {
      if (!drag) return;
      drag = null;
      const rect = root.getBoundingClientRect();
      GM_setValue('biliSync.pos', JSON.stringify({ left: Math.round(rect.left), top: Math.round(rect.top) }));
    });
    root.addEventListener('pointerdown', (event) => event.stopPropagation());
    root.addEventListener('keydown', (event) => event.stopPropagation());
    document.body.appendChild(root);
    const nickInput = document.getElementById('bili-sync-nick');
    nickInput.addEventListener('change', () => GM_setValue('biliSync.nick', nickInput.value.trim()));
    const code = document.getElementById('bili-sync-code');
    code.addEventListener('input', () => {
      code.value = code.value.toUpperCase().replace(/[^A-Z0-9]/g, '').slice(0, 6);
    });
  }

  async function runAction(fn) {
    try {
      await fn();
    } catch (err) {
      setStatus(err.message || '操作失败', 'err');
    }
  }

  async function testBackend() {
    try {
      const data = await api('health', null, 'GET');
      if (!data.configured) {
        setStatus('后端还没初始化，请用浏览器打开这个地址', 'warn');
        return;
      }
      if (!data.db) {
        setStatus('后端已配置，但数据库没连上', 'err');
        return;
      }
      setStatus('连接正常 · v' + (data.version || ''), 'ok');
    } catch (err) {
      setStatus(err.message || '连接失败', 'err');
    }
  }

  function saveSettings() {
    try {
      const url = backendFromInput();
      const previous = storedBackend();
      GM_setValue('biliSync.backend', url);
      GM_setValue('biliSync.nick', nickname() === '用户' ? '' : nickname());
      const nickInput = document.getElementById('bili-sync-nick');
      if (nickInput && nickname() !== '用户') nickInput.value = nickname();
      if (session && session.backend !== url) {
        clearSession();
        paint();
      } else if (previous !== url && session) {
        session.backend = url;
        persistSession();
      }
      setStatus('设置已保存', 'ok');
    } catch (err) {
      setStatus(err.message || '保存失败', 'err');
    }
  }

  function resume() {
    let saved = null;
    try {
      saved = loadSession();
    } catch (err) {
      saved = null;
    }
    if (!saved || saved.backend !== storedBackend()) return;
    session = saved;
    startLoop();
    setStatus('正在恢复房间…', 'warn');
    paint();
  }

  function mount() {
    if (!document.body) {
      document.addEventListener('DOMContentLoaded', mount, { once: true });
      return;
    }
    if (document.getElementById('bili-sync-root')) return;
    buildPanel();
    bindHistory();
    setInterval(checkUrl, 500);
    setInterval(() => {
      const video = getVideo();
      if (video) bindVideo(video);
      if (session && lastRoom) applyPlayback(lastRoom);
      paintDrift();
    }, 400);
    document.addEventListener('fullscreenchange', relocate);
    document.addEventListener('webkitfullscreenchange', relocate);
    resume();
  }

  mount();
})();
