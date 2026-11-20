/* Energy Manager - page de simulation complète.
 * Le vrai moteur de décision (PHP) est appelé à chaque cycle sur un état simulé ; l'installation n'est ni lue ni commandée.
 * Le modèle physique ci-dessous (batterie, ballon, pièces, Tesla) est volontairement simple : il sert à voir la logique, pas à prédire au watt près.
 */
(function () {
  'use strict'
  if (!$('#emSim').length) { return }

  var AJAX = 'plugins/energyManager/core/ajax/energyManager.ajax.php'
  var CAP_WH = 16000      // capacité batterie
  var THERMO = 60         // °C : thermostat du ballon
  var WATER_L = 200       // litres
  var TESLA_MIN = 460     // W : 2 A mini
  var TESLA_MAX = 7360    // W : 32 A x 230 V
  var TESLA_UP = 10       // s : confirmation d'une hausse (teslasmartcharge 1.2)
  var TESLA_DOWN = 60     // s : confirmation d'une baisse
  var TESLA_SEUIL = 80    // % : seuil batterie de teslasmartcharge en priorité « maison » (réglage du plugin Tesla)
  var CYCLE = 10          // s : cadence de décision

  var S = null            // état simulé
  var timer = null
  var inflight = false
  var logs = []
  var alertLast = {}

  /* ------------------------------------------------------------------ utilitaires */
  var num = function (v) { var n = parseFloat(v); return isNaN(n) ? 0 : n }
  var clamp = function (v, a, b) { return Math.max(a, Math.min(b, v)) }
  var pad = function (n) { return (n < 10 ? '0' : '') + n }
  var esc = function (t) { return String(t).replace(/[&<>"']/g, function (c) { return { '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&#39;' }[c] }) }
  var W = function (v) { return Math.round(num(v)) + ' W' }
  var el = function (id) { return document.getElementById('ems-' + id) }

  function clockText(t) {
    var d = new Date(t * 1000)
    return pad(d.getHours()) + ':' + pad(d.getMinutes()) + ':' + pad(d.getSeconds())
  }
  function minuteOf(t) {
    var d = new Date(t * 1000)
    return d.getHours() * 60 + d.getMinutes()
  }
  function log(kind, text) {
    logs.unshift({ t: clockText(S ? S.t : Date.now() / 1000), k: kind, x: text })
    if (logs.length > 200) { logs.pop() }
    var html = ''
    logs.forEach(function (l) { html += '<div><span class="t">' + l.t + '</span><span class="' + l.k + '">' + esc(l.x) + '</span></div>' })
    el('log').innerHTML = html
  }
  function call(action, data, ok) {
    $.ajax({
      type: 'POST', url: AJAX, dataType: 'json', data: $.extend({ action: action }, data),
      success: function (r) {
        if (r.state !== 'ok') { inflight = false; log('err', 'Erreur : ' + r.result); return }
        ok(r.result)
      },
      error: function () { inflight = false; log('err', 'Erreur de communication avec Jeedom') }
    })
  }
  function byType(type) { return S.equips.filter(function (e) { return e.type === type }) }
  function byId(id) { for (var i = 0; i < S.equips.length; i++) { if (S.equips[i].id == id) { return S.equips[i] } } return null }

  /* ------------------------------------------------------------------ jauges actionnables */
  var gauges = []
  function makeGauge(side, g) {
    var $g = $('<div class="ems-gauge" tabindex="0" role="slider"><div class="ems-gv"></div><div class="ems-gt"><div class="ems-gf"></div><div class="ems-gh"></div></div><div class="ems-gl"></div></div>')
    $g.find('.ems-gl').text(g.label)
    $g.find('.ems-gt')[0].style.setProperty('--c', g.color)
    $('#ems-' + side).append($g)
    g.$ = $g
    var track = $g.find('.ems-gt')[0]
    function setFrom(clientY) {
      var r = track.getBoundingClientRect()
      var f = 1 - (clientY - r.top) / r.height
      var v = g.min + clamp(f, 0, 1) * (g.max - g.min)
      v = Math.round(v / g.step) * g.step
      g.set(clamp(v, g.min, g.max)); refresh(g)
    }
    var drag = false
    $(track).on('pointerdown', function (ev) { drag = true; track.setPointerCapture(ev.originalEvent.pointerId); setFrom(ev.originalEvent.clientY) })
    $(track).on('pointermove', function (ev) { if (drag) { setFrom(ev.originalEvent.clientY) } })
    $(track).on('pointerup pointercancel', function () { drag = false })
    $g.find('.ems-gv').on('click', function () {
      var v = window.prompt(g.label + ' (' + g.min + ' à ' + g.max + g.unit + ')', String(g.get()))
      if (v !== null && v !== '' && !isNaN(parseFloat(v))) { g.set(clamp(parseFloat(v), g.min, g.max)); refresh(g) }
    })
    $g.on('keydown', function (ev) {
      var d = ev.key === 'ArrowUp' || ev.key === 'ArrowRight' ? 1 : (ev.key === 'ArrowDown' || ev.key === 'ArrowLeft' ? -1 : 0)
      if (!d) { return }
      ev.preventDefault()
      g.set(clamp(g.get() + d * g.step * (ev.shiftKey ? 10 : 1), g.min, g.max)); refresh(g)
    })
    gauges.push(g)
  }
  function refresh(g) {
    var v = g.get()
    g.$.toggleClass('off', v === null)
    if (v === null) { g.$.find('.ems-gv').text('--'); return }
    var p = (v - g.min) / (g.max - g.min) * 100
    g.$.find('.ems-gf').css('height', p + '%')
    g.$.find('.ems-gh').css('bottom', 'calc(' + p + '% - 2px)')
    g.$.find('.ems-gv').text((g.step < 1 ? v.toFixed(1) : Math.round(v)) + g.unit)
    g.$.attr('aria-valuenow', v).attr('aria-valuemin', g.min).attr('aria-valuemax', g.max)
  }
  function buildGauges() {
    gauges = []
    $('#ems-left, #ems-right').empty()
    var avg = function (list, key) { return list.length ? list[0][key] : null }
    makeGauge('left', { label: '☀ Solaire disponible', min: 0, max: 8000, step: 50, unit: ' W', color: '#f5a623',
      get: function () { return S.pvAvail }, set: function (v) { S.pvAvail = v } })
    makeGauge('left', { label: '🏠 Talon maison (hors consommateurs pilotés)', min: 0, max: 6000, step: 50, unit: ' W', color: '#4ec9b0',
      get: function () { return S.talon }, set: function (v) { S.talon = v } })
    makeGauge('left', { label: '🔋 SOC batterie', min: 0, max: 100, step: 1, unit: ' %', color: '#3ecf63',
      get: function () { return S.soc }, set: function (v) { S.soc = v } })
    makeGauge('right', { label: '💧 Eau du ballon', min: 10, max: 70, step: 1, unit: ' °C', color: '#4a90d9',
      get: function () { return avg(byType('ecs'), 'water_temp') },
      set: function (v) { byType('ecs').forEach(function (e) { e.water_temp = v }) } })
    makeGauge('right', { label: '🌡 Température des pièces', min: 5, max: 28, step: 0.5, unit: ' °C', color: '#d9534f',
      get: function () { return avg(byType('simple'), 'temp') },
      set: function (v) { byType('simple').forEach(function (e) { e.temp = v }) } })
    makeGauge('right', { label: '🚗 Marge Tesla (négative = soutirage EDF toléré)', min: -300, max: 300, step: 50, unit: ' W', color: '#d98a4a',
      get: function () { var t = byType('tesla'); return t.length ? (t[0].margin || 0) : null },
      set: function (v) { byType('tesla').forEach(function (e) { e.margin = v }) } })
    makeGauge('right', { label: '🌙 Réserve de nuit : SOC à laisser au soir', min: 0, max: 100, step: 5, unit: ' %', color: '#8e7cc3',
      get: function () { return S.reserve }, set: function (v) { S.reserve = v } })
    makeGauge('right', { label: '🔮 Prévision solaire restante (si cochée)', min: 0, max: 40, step: 1, unit: ' kWh', color: '#f5d76e',
      get: function () { return S.forecast }, set: function (v) { S.forecast = v } })
    gauges.forEach(refresh)
  }

  /* ------------------------------------------------------------------ modèle physique */
  function solarFactor(t) {
    var d = new Date(t * 1000), h = d.getHours() + d.getMinutes() / 60
    return (h > 7 && h < 19) ? Math.sin(Math.PI * (h - 7) / 12) : 0
  }

  function physics(dt) {
    var cfg = S.cfg
    // nouveau jour : le ballon peut de nouveau être "terminé"
    var day = new Date(S.t * 1000).toDateString()
    if (S.day !== day) { S.day = day; byType('ecs').forEach(function (e) { e.done_today = false; e.force_edf = false; e._ran = false }) }

    S.pvA = S.auto ? S.pvAvail * solarFactor(S.t) : S.pvAvail

    S.equips.forEach(function (e) {
      if (e.type === 'ecs') {
        e.power_real = (e.is_on && e.water_temp < THERMO) ? e.power_theo : 0
        e.water_temp += (e.power_real * dt / 3600) / (WATER_L * 1.163) - 0.4 * dt / 3600
        // thermostat atteint : consommation retombée à zéro après avoir chauffé => ballon "terminé" (comme le plugin réel)
        if (e.power_real > e.zero_threshold) { e._ran = true; e._zero = 0 }
        else if (e.is_on && e._ran) { e._zero = (e._zero || 0) + dt }
        if (e.is_on && e._ran && (e._zero || 0) >= e.zero_delay) {
          e.done_today = true; e._ran = false; e._zero = 0
          log('info', e.name + ' : thermostat atteint (consommation à zéro), ballon terminé pour la journée')
          if (e.cut_on_zero) { e.is_on = false; log('act', e.name + ' → OFF : ballon : thermostat atteint, passage inactif pour la journée') }
        }
      } else if (e.type === 'simple') {
        e.power_real = e.is_on ? e.power_theo : 0
        e.temp += (e.power_real / 600) * dt / 3600 - 0.3 * dt / 3600     // chauffe ~1,7 K/h par kW, déperditions 0,3 K/h
        e.temp = Math.max(e.temp, 5)
      }
    })

    // Tesla : reproduit teslasmartcharge (mode Solaire). Surplus = puissance batterie (charge +) - réseau (soutirage +) + puissance déjà prise
    // par la voiture ; paliers de 230 W (2 à 32 A) ; un changement doit être confirmé 60 s dans le même sens ; coupure immédiate si la
    // maison se vide (batterie < -50 W) ; en priorité « maison », la voiture attend que la batterie dépasse son seuil.
    byType('tesla').forEach(function (e) {
      // la voiture débranchée = Stand-By (géré par teslasmartcharge) ; branchée, c'est energyManager qui passe en Solaire
      if (!e.plugged && e.tesla_mode !== 'Non actif') { e.tesla_mode = 'Stand-By'; e.plug_handled = false }
      e.active = (e.tesla_mode === 'Solaire')
      e.mode = (e.tesla_mode === 'Non actif') ? 'off' : 'auto'
      if (!e.active) { e.power_real = 0; e._t = 0; e._dir = 0 } else {
        var allow = S.batt - S.grid + e.power_real - (e.margin || 0)     // marge de teslasmartcharge : négative = soutirage EDF toléré
        var amps = Math.min(32, Math.floor(allow / 230))
        if (amps < 2) { amps = 0 }
        var cap = e.power_theo || TESLA_MAX
        var target = Math.min(amps * 230, cap)
        var why = 'surplus ' + Math.round(allow) + ' W (batterie ' + Math.round(S.batt) + ' − réseau ' + Math.round(S.grid) + ' + voiture ' + Math.round(e.power_real) + ')'
        if (amps === 0) { why += ' < ' + TESLA_MIN + ' W (2 A mini) : pas de charge' }
        else { why += ' → ' + amps + ' A' + (amps * 230 > cap ? ' (plafonné à la puissance maximale ' + Math.round(cap) + ' W)' : '') }
        if (S.prio === 'battery' && S.soc < TESLA_SEUIL) { target = 0; why = 'priorité maison : la voiture attend que le SOC (' + Math.round(S.soc) + ' %) dépasse ' + TESLA_SEUIL + ' %' }
        if (S.batt < -50 && target < e.power_real) { e.power_real = target; e._t = 0; e._dir = 0; why += ' · coupure immédiate (la maison se vide)' }
        else if (target === e.power_real) { e._t = 0; e._dir = 0; why += ' · stable' }
        else {
          var dir = target > e.power_real ? 1 : -1
          var delay = dir === 1 ? TESLA_UP : TESLA_DOWN        // hausses confirmées 10 s, baisses 60 s (teslasmartcharge 1.2)
          if (dir !== e._dir) { e._dir = dir; e._t = 0 }
          e._t += dt
          why += ' · ' + (dir === 1 ? 'hausse' : 'baisse') + ' vers ' + Math.round(target) + ' W dans ' + Math.max(0, Math.round(delay - e._t)) + ' s'
          if (e._t >= delay) { e.power_real = target; e._t = 0; e._dir = 0 }
        }
        e._info = why
      }
      e.is_on = e.power_real > 20
    })

    var cons = 0
    S.equips.forEach(function (e) { cons += e.power_real })
    S.house = S.talon + cons
    S.pv = Math.min(S.pvA, S.hoy)
    var net = S.pv - S.house
    if (net >= 0) {
      // surplus : la batterie se charge d'abord (limite de charge), le reste part sur le réseau
      var cl = S.soc < 100 ? Math.min(S.chg, cfg.charge_max) : 0
      S.batt = Math.min(net, cl)
      S.grid = -(net - S.batt)
    } else {
      // déficit : batterie d'abord, jamais de charge depuis EDF
      var d = S.soc > cfg.soc_min ? Math.min(-net, S.inv) : 0
      S.batt = -d
      S.grid = -net - d
    }
    S.soc = clamp(S.soc + S.batt * dt / 3600 / CAP_WH * 100, 0, 100)
  }

  /* ------------------------------------------------------------------ décision (vrai moteur PHP) */
  function describe(a) {
    if (a.type === 'equip') { var e = byId(a.id); return (e ? e.name : '#' + a.id) + ' → ' + String(a.cmd).toUpperCase() + ' : ' + a.reason }
    if (a.type === 'tesla_mode') { var tm = byId(a.id); return (tm ? tm.name : '#' + a.id) + ' → mode ' + a.value + ' : ' + a.reason }
    if (a.type === 'hoymiles') { return 'Limite Hoymiles → ' + a.value + ' W : ' + a.reason }
    if (a.type === 'inv_limit') { return 'Limite onduleur Victron → ' + a.value + ' W : ' + a.reason }
    if (a.type === 'charge_limit') { return 'Limite de charge batterie → ' + a.value + ' W : ' + a.reason }
    return JSON.stringify(a)
  }

  function decide() {
    if (!S.active) {
      S.decision = 'Pilotage désactivé (interrupteur rouge) : rien n\'est décidé ni commandé'
      if (!S.safeDone) {
        S.safeDone = true
        ;[['hoy', 'Hoymiles', 'hoy_max'], ['inv', 'onduleur Victron', 'inv_max'], ['chg', 'charge batterie', 'charge_max']].forEach(function (l) {
          if (S[l[0]] < num(S.cfg[l[2]]) - 5) { S[l[0]] = num(S.cfg[l[2]]); log('act', 'Limite ' + l[1] + ' → ' + S[l[0]] + ' W : pilotage désactivé : limite remise à sa valeur maximale') }
        })
      }
      S.decisionAlert = false
      return
    }
    S.safeDone = false
    inflight = true
    var state = {
      now: Math.floor(S.t), minute: minuteOf(S.t), grid: S.grid, pv: S.pv, soc: S.soc, batt: S.batt, house: S.house,
      stale: S.stale, cfg: { batt_target: S.target, tesla_priority: S.prio, night_reserve: S.reserve }, hoy_limit: S.hoy, charge_limit: S.chg, inv_limit: S.inv,
      forecast_kwh: S.useForecast ? S.forecast : null,
      equips: S.equips, mem: S.mem
    }
    call('sim_step', { state: JSON.stringify(state) }, function (res) {
      inflight = false
      var r = res.result
      S.mem = r.mem || {}
      S.slot = res.slot
      S.decision = (r.info && r.info.decision) ? r.info.decision : ''
      S.decisionAlert = r.alerts.length > 0
      S.night = r.info && r.info.night_projected !== undefined ? { proj: r.info.night_projected, may: r.info.ecs_may_take === 1 } : null
      r.actions.forEach(function (a) {
        log('act', describe(a))
        if (a.type === 'equip') {
          var e = byId(a.id)
          if (e) { e.is_on = (a.cmd === 'on'); if (!e.is_on) { e.power_real = 0 } }
        } else if (a.type === 'tesla_mode') { var te = byId(a.id); if (te) { te.tesla_mode = a.value; te.plug_handled = true } }
        else if (a.type === 'hoymiles') { S.hoy = num(a.value) }
        else if (a.type === 'charge_limit') { S.chg = num(a.value) }
        else if (a.type === 'inv_limit') { S.inv = num(a.value) }
      })
      r.alerts.forEach(function (al) {
        var last = alertLast[al.key]
        if (last === undefined || S.t - last >= 300) {   // alerte renvoyée en boucle toutes les 5 minutes
          log('alert', '🚨 ' + al.message + (last === undefined ? '' : ' (alerte répétée)'))
          alertLast[al.key] = S.t
        }
      })
      Object.keys(alertLast).forEach(function (k) {
        if (!r.alerts.some(function (al) { return al.key === k })) { delete alertLast[k]; log('info', 'Alerte « ' + k + ' » résolue') }
      })
      render()
    })
  }

  /* ------------------------------------------------------------------ boucle */
  function tick() {
    if (!$('#emSim').length) { clearInterval(timer); return }
    if (!S || !S.running) { return }
    var dt = S.speed
    S.t += dt
    physics(dt)
    S.since += dt
    if (!inflight && S.since >= CYCLE) { S.since = 0; decide() }
    render()
  }

  /* ------------------------------------------------------------------ affichage */
  function setFlow(id, value, rev) {
    var on = Math.abs(num(value)) > 10
    el(id).setAttribute('class', 'flow' + (on ? ' on' : '') + ((on && ((num(value) > 0) === rev)) ? ' rev' : ''))
  }
  function mark($b, on) { $b.toggleClass('active', !!on) }

  function render() {
    el('pv').textContent = W(S.pv)
    el('pvavail').textContent = 'dispo ' + W(S.pvA)
    el('grid').textContent = (S.grid < 0 ? 'injection ' : 'soutirage ') + W(Math.abs(S.grid))
    el('house').textContent = W(S.house)
    el('bat').textContent = Math.round(S.soc) + ' % · ' + W(S.batt)
    el('inj').textContent = 'injection ' + W(Math.max(0, -S.grid))
    el('lim').textContent = 'Hoymiles ' + W(S.hoy) + ' · charge ' + W(S.chg)
    el('lim2').textContent = 'onduleur ' + W(S.inv)
    setFlow('fpv', S.pv, false); setFlow('fgrid', S.grid, false); setFlow('fbat', S.batt, false); setFlow('fhouse', S.house, false)

    el('clock').textContent = clockText(S.t)
    var $slot = $(el('slot')); $slot.text(S.slot || 'HP').toggleClass('hc', S.slot === 'HC')
    if (!S.dragTime) { el('time').value = minuteOf(S.t) }
    el('play').innerHTML = S.running ? '<i class="fas fa-pause"></i>' : '<i class="fas fa-play"></i>'

    el('active').className = 'ems-led ' + (S.active ? 'ems-g' : 'ems-r')
    el('activetxt').textContent = S.active ? 'Pilotage actif' : 'Pilotage désactivé : rien n\'est commandé'
    mark($(el('bpt')), S.prio === 'tesla'); mark($(el('bpb')), S.prio === 'battery')
    mark($(el('b95')), S.target === 95); mark($(el('b100')), S.target === 100)

    var d = el('decision')
    d.textContent = S.decision || 'En attente du premier cycle…'
    d.className = 'ems-decision' + (S.decisionAlert ? ' alert' : '')
    el('night').textContent = S.night
      ? 'Réserve de nuit : SOC projeté au soir ' + S.night.proj + ' % (réserve demandée ' + Math.round(S.reserve) + ' %) → le ballon '
        + (S.night.may ? 'PEUT passer avant la batterie' : 'ne passe PAS avant la batterie : injection réelle ou heure creuse EDF seulement')
      : ''

    var html = ''
    S.equips.forEach(function (e, i) {
      var st = (e.type === 'ecs') ? 'eau ' + e.water_temp.toFixed(1) + ' °C' + (e.done_today ? ' · terminé' : '')
        : (e.type === 'simple') ? 'pièce ' + e.temp.toFixed(1) + ' °C (cible ' + e.target + ')'
        : 'teslasmartcharge : ' + e.tesla_mode + (e.plugged ? ' · branchée' : ' · débranchée') + (e.active && e._info ? ' — ' + e._info : '')
      html += '<div class="ems-tile ' + (e.is_on ? 'on' : '') + (e.piloted ? '' : ' unpiloted') + '">'
        + '<span class="ems-led ' + (e.piloted ? 'ems-g' : 'ems-r') + '" data-act="led" data-i="' + i + '" title="Piloté par energyManager : cliquer pour changer"></span>'
        + esc(e.name) + '<b>' + Math.round(e.power_real) + ' W</b>' + (e.is_on ? 'ON' : 'OFF') + '<div class="ems-st">' + esc(st) + '</div>'
      if (e.type === 'tesla') {
        html += '<select data-act="tmode" data-i="' + i + '">'
        ;[['plug', 'Branchée'], ['unplug', 'Débranchée'], ['off', 'Non actif']].forEach(function (m) {
          var cur = (e.tesla_mode === 'Non actif') ? 'off' : (e.plugged ? 'plug' : 'unplug')
          html += '<option value="' + m[0] + '"' + (cur === m[0] ? ' selected' : '') + '>' + m[1] + '</option>'
        })
        html += '</select>'
        html += ' <select data-act="aggr" data-i="' + i + '" title="Agressivité : paliers libérés sur la limite Hoymiles">'
        ;[[500, 'Petite 500 W'], [1000, 'Moyenne 1000 W'], [2000, 'Grande 2000 W']].forEach(function (m) {
          html += '<option value="' + m[0] + '"' + (num(e.aggr_w || 1000) === m[0] ? ' selected' : '') + '>' + m[1] + '</option>'
        })
        html += '</select>'
      } else {
        ;[['auto', 'Auto'], ['on', 'ON'], ['off', 'OFF']].forEach(function (m) {
          html += '<a class="btn btn-xs btn-default' + (e.mode === m[0] ? ' active' : '') + '" data-act="mode" data-i="' + i + '" data-v="' + m[0] + '">' + m[1] + '</a>'
        })
        if (e.type === 'ecs') { html += '<a class="btn btn-xs btn-default' + (e.force_edf ? ' active' : '') + '" data-act="edf" data-i="' + i + '" title="Simule la réponse « Oui » à la demande Discord">Compléter EDF</a>' }
      }
      html += '</div>'
    })
    var cons = el('cons')
    var busy = document.activeElement && document.activeElement.tagName === 'SELECT' && cons.contains(document.activeElement)
    if (!busy && cons.getAttribute('data-h') !== html) { cons.innerHTML = html; cons.setAttribute('data-h', html) }
    gauges.forEach(refresh)
  }

  /* ------------------------------------------------------------------ initialisation */
  function start(init) {
    S = {
      t: (function () { var d = new Date(); d.setHours(12, 0, 0, 0); return d.getTime() / 1000 })(),
      pvAvail: 4000, talon: 500, soc: 60, auto: false, stale: false, active: true, running: true, speed: num($('#ems-speed').val()) || 10,
      cfg: init.cfg, hoy: num(init.cfg.hoy_max), chg: num(init.cfg.charge_max), inv: num(init.cfg.inv_max),
      prio: init.tesla_priority === 'battery' ? 'battery' : 'tesla', target: init.batt_target === 95 ? 95 : 100,
      reserve: num(init.cfg.night_reserve), forecast: 10, useForecast: false, night: null,
      equips: init.equips, mem: {}, since: CYCLE, slot: 'HP', decision: '', decisionAlert: false, day: null,
      pv: 0, pvA: 0, grid: 0, batt: 0, house: 0, dragTime: false
    }
    S.equips.forEach(function (e) { if (e.piloted === undefined) { e.piloted = true } })
    logs = []; alertLast = {}
    $('#ems-demo').toggle(!!init.demo)
    $('#ems-forecast').prop('checked', false); $('#ems-auto').prop('checked', false); $('#ems-stale').prop('checked', false); $('#ems-heat').prop('checked', true)
    if (!bound) { bound = true; bind() }
    buildGauges()
    log('info', 'Réserve de nuit réelle (reprise dans la jauge) : ' + init.reserve_text)
    log('info', 'Simulation démarrée à ' + clockText(S.t) + ' — ' + S.equips.length + ' équipement(s)' + (init.demo ? ' de démonstration' : ' (configuration réelle du plugin)') + '. Aucun ordre n\'est envoyé.')
    render()
    if (timer) { clearInterval(timer) }
    timer = setInterval(tick, 1000)
  }
  function load() {
    inflight = false
    call('sim_init', {}, start)
  }

  /* ------------------------------------------------------------------ commandes de la page */
  var bound = false
  function bind() {
    $('#ems-play').on('click', function () { S.running = !S.running; render() })
    $('#ems-speed').on('change', function () { S.speed = num($(this).val()) || 10 })
    $('#ems-reset').on('click', load)
    $('#ems-time').on('pointerdown', function () { S.dragTime = true })
    $('#ems-time').on('pointerup pointercancel blur', function () { S.dragTime = false })
    $('#ems-time').on('input', function () {
      var m = num($(this).val()), d = new Date(S.t * 1000)
      d.setHours(Math.floor(m / 60), m % 60, 0, 0)
      S.t = d.getTime() / 1000; S.mem = {}; S.since = CYCLE
      log('info', 'Heure simulée réglée sur ' + clockText(S.t))
      render()
    })
    $('#ems-active').on('click', function () { S.active = !S.active; log('info', S.active ? 'Pilotage activé' : 'Pilotage désactivé'); S.since = CYCLE; render() })
    $('#ems-bpt').on('click', function () { S.prio = 'tesla'; log('info', 'Priorité : Tesla'); render() })
    $('#ems-bpb').on('click', function () { S.prio = 'battery'; log('info', 'Priorité : Batterie'); render() })
    $('#ems-b95').on('click', function () { S.target = 95; log('info', 'Cible batterie : 95 %'); render() })
    $('#ems-b100').on('click', function () { S.target = 100; log('info', 'Cible batterie : 100 %'); render() })
    $('#ems-auto').on('change', function () { S.auto = $(this).is(':checked') })
    $('#ems-forecast').on('change', function () { S.useForecast = $(this).is(':checked'); S.since = CYCLE })
    $('#ems-stale').on('change', function () { S.stale = $(this).is(':checked'); log('info', S.stale ? 'Panne de mesure simulée' : 'Mesure rétablie'); S.since = CYCLE })
    $('#ems-heat').on('change', function () {
      var on = $(this).is(':checked')
      byType('simple').forEach(function (e) { e.season_heat = on })
    })
    $('#ems-cons').on('click', '[data-act]', function () {
      var a = $(this).data('act'), e = S.equips[parseInt($(this).data('i'), 10)]
      if (!e) { return }
      if (a === 'led') {
        e.piloted = !e.piloted
        log('info', e.name + (e.piloted ? ' : piloté par le plugin' : ' : non piloté (ignoré)'))
        if (!e.piloted) {
          // un équipement qu'on cesse de piloter est éteint proprement
          if (e.type === 'tesla') { e.tesla_mode = 'Non actif'; log('act', e.name + ' → Stand-By (arrêt de la charge) puis Non actif : plus piloté') }
          else { e.is_on = false; e.power_real = 0; log('act', e.name + ' → OFF : plus piloté') }
        } else if (e.type === 'tesla' && e.tesla_mode === 'Non actif') {
          e.tesla_mode = 'Stand-By'; e.plug_handled = false
          log('act', e.name + ' → Stand-By : de nouveau piloté')
        }
      }
      else if (a === 'mode') { e.mode = String($(this).data('v')); log('info', e.name + ' : mode ' + e.mode) }
      else if (a === 'edf') { e.force_edf = !e.force_edf; log('info', e.name + (e.force_edf ? ' : complément EDF validé (réponse Discord « Oui »)' : ' : complément EDF annulé')) }
      S.since = CYCLE
      render()
    })
    $('#ems-cons').on('change', 'select[data-act=aggr]', function () {
      var e = S.equips[parseInt($(this).data('i'), 10)]
      if (e) { e.aggr_w = num($(this).val()); log('info', e.name + ' : agressivité ' + e.aggr_w + ' W') }
    })
    $('#ems-cons').on('change', 'select[data-act=tmode]', function () {
      var e = S.equips[parseInt($(this).data('i'), 10)]
      if (!e) { return }
      var v = $(this).val()
    if (v === 'off') { e.tesla_mode = 'Non actif'; e.plugged = true; log('info', e.name + ' : teslasmartcharge passe en « Non actif »') }
    else if (v === 'plug') { e.plugged = true; if (e.tesla_mode === 'Non actif') { e.tesla_mode = 'Stand-By' } e.plug_handled = false; log('info', e.name + ' : voiture branchée') }
    else { e.plugged = false; e.tesla_mode = 'Stand-By'; e.plug_handled = false; log('info', e.name + ' : voiture débranchée (Stand-By)') }
      S.since = CYCLE
      render()
    })
  }

  load()
})()
