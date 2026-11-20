/* This file is part of Jeedom.
 *
 * Jeedom is free software: you can redistribute it and/or modify
 * it under the terms of the GNU General Public License as published by
 * the Free Software Foundation, either version 3 of the License, or
 * (at your option) any later version.
 *
 * Jeedom is distributed in the hope that it will be useful,
 * but WITHOUT ANY WARRANTY; without even the implied warranty of
 * MERCHANTABILITY or FITNESS FOR A PARTICULAR PURPOSE. See the
 * GNU General Public License for more details.
 *
 * You should have received a copy of the GNU General Public License
 * along with Jeedom. If not, see <http://www.gnu.org/licenses/>.
 */

/* Affiche uniquement les champs du type d'équipement choisi */
function emShowType() {
  var type = $('#sel_emType').val() || 'simple'
  $('.emType').hide()
  $('.emType-' + type).show()
}

$('#sel_emType').off('change').on('change', emShowType)
$('.eqLogicAction[data-action=add]').on('click', function () { setTimeout(emShowType, 300) })
$('.eqLogicDisplayCard').on('click', function () { setTimeout(emShowType, 500) })

/* Sélecteur de commande des champs de l'équipement */
$('body').off('click', '.emEqCmdPick').on('click', '.emEqCmdPick', function () {
  var input = $(this).closest('.input-group').find('input')
  jeedom.cmd.getSelectModal({ cmd: { type: $(this).data('type') } }, function (result) {
    input.value(result.human)
  })
})

/* Simulateur : exécute le moteur sur des valeurs saisies, sans rien envoyer */
$('#bt_emSimulator').off('click').on('click', function () {
  var html = '<div class="form-horizontal" id="emSim">'
  var fields = [
    ['pv', 'Production PV (W)', 4000], ['grid', 'Réseau (W, négatif = injection)', -1200],
    ['soc', 'SOC batterie (%)', 100], ['hour', 'Heure', 12], ['min', 'Minute', 0],
    ['hoy_limit', 'Limite Hoymiles actuelle (W)', 7600], ['charge_limit', 'Limite de charge actuelle (W)', 0],
    ['room_temp', 'Température des pièces (°C)', 18], ['water_temp', 'Température du ballon (°C)', 40]
  ]
  fields.forEach(function (f) {
    html += '<div class="form-group"><label class="col-sm-6 control-label">' + f[1] + '</label>' +
      '<div class="col-sm-4"><input type="number" step="any" class="form-control" data-sim="' + f[0] + '" value="' + f[2] + '"/></div></div>'
  })
  html += '<div class="form-group"><label class="col-sm-6 control-label">Saison de chauffe</label><div class="col-sm-4"><input type="checkbox" data-sim="season_heat" checked/></div></div>'
  html += '<div class="form-group"><label class="col-sm-6 control-label">Heure creuse d\'après-midi</label><div class="col-sm-4"><input type="checkbox" data-sim="hc_afternoon"/></div></div>'
  html += '<a class="btn btn-success" id="bt_emSimRun"><i class="fas fa-play"></i> Simuler</a><pre id="emSimOut" style="margin-top:10px;max-height:300px;overflow:auto"></pre></div>'
  bootbox.dialog({ title: '{{Simulateur de décisions (aucun ordre envoyé)}}', message: html, size: 'large' })
  $('#bt_emSimRun').on('click', function () {
    var inputs = {}
    $('#emSim [data-sim]').each(function () {
      inputs[$(this).data('sim')] = $(this).is(':checkbox') ? ($(this).is(':checked') ? 1 : 0) : $(this).val()
    })
    $.ajax({
      type: 'POST', url: 'plugins/energyManager/core/ajax/energyManager.ajax.php', dataType: 'json',
      data: { action: 'simulate', inputs: JSON.stringify(inputs) },
      success: function (data) {
        if (data.state != 'ok') { $('#emSimOut').text(data.result); return }
        var r = data.result.result, names = data.result.names, out = ''
        if (r.alerts.length) { r.alerts.forEach(function (a) { out += '⚠ ' + a.message + '\n' }) }
        if (!r.actions.length) { out += 'Aucune action.\n' }
        r.actions.forEach(function (a) {
          if (a.type == 'equip') { out += (names[a.id] || a.id) + ' → ' + a.cmd.toUpperCase() + ' : ' + a.reason + '\n' }
          else if (a.type == 'hoymiles') { out += 'Limite Hoymiles → ' + a.value + ' W : ' + a.reason + '\n' }
          else if (a.type == 'charge_limit') { out += 'Limite charge batterie → ' + a.value + ' W : ' + a.reason + '\n' }
        })
        out += '\n' + r.info.decision
        $('#emSimOut').text(out)
      }
    })
  })
})

/* Tableau des commandes */
function addCmdToTable(_cmd) {
  if (!isset(_cmd)) { var _cmd = { configuration: {} } }
  if (!isset(_cmd.configuration)) { _cmd.configuration = {} }
  var tr = '<tr class="cmd" data-cmd_id="' + init(_cmd.id) + '">'
  tr += '<td class="hidden-xs"><span class="cmdAttr" data-l1key="id"></span></td>'
  tr += '<td><input class="cmdAttr form-control input-sm" data-l1key="name" placeholder="{{Nom de la commande}}"></td>'
  tr += '<td><span class="type" type="' + init(_cmd.type) + '">' + jeedom.cmd.availableType() + '</span><span class="subType" subType="' + init(_cmd.subType) + '"></span></td>'
  tr += '<td>'
  tr += '<label class="checkbox-inline"><input type="checkbox" class="cmdAttr" data-l1key="isVisible" checked/>{{Afficher}}</label> '
  tr += '<label class="checkbox-inline"><input type="checkbox" class="cmdAttr" data-l1key="isHistorized" checked/>{{Historiser}}</label> '
  tr += '</td>'
  tr += '<td>'
  if (is_numeric(_cmd.id)) {
    tr += '<a class="btn btn-default btn-xs cmdAction" data-action="configure"><i class="fas fa-cogs"></i></a> '
    tr += '<a class="btn btn-default btn-xs cmdAction" data-action="test"><i class="fas fa-rss"></i> {{Tester}}</a>'
  }
  tr += ' <i class="fas fa-minus-circle pull-right cmdAction cursor" data-action="remove" title="{{Supprimer la commande}}"></i></td>'
  tr += '</tr>'
  $('#table_cmd tbody').append(tr)
  var tr = $('#table_cmd tbody tr').last()
  jeedom.eqLogic.buildSelectCmd({
    id: $('.eqLogicAttr[data-l1key=id]').value(),
    filter: { type: 'info' },
    error: function (error) { $('#div_alert').showAlert({ message: error.message, level: 'danger' }) },
    success: function (result) {
      tr.setValues(_cmd, '.cmdAttr')
      jeedom.cmd.changeType(tr, init(_cmd.subType))
    }
  })
}
