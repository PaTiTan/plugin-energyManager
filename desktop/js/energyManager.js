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

/* Appelé par Jeedom à l'ouverture d'un équipement : défauts des champs vides et affichage du bon type */
function printEqLogic(_eqLogic) {
  var c = (_eqLogic && _eqLogic.configuration) ? _eqLogic.configuration : {}
  var unset = function (v) { return v === undefined || v === null || v === '' }
  var f = function (k) { return $('.eqLogicAttr[data-l1key=configuration][data-l2key=' + k + ']') }
  if (unset(c.cut_on_zero)) { f('cut_on_zero').prop('checked', true) }
  if (unset(c.priority)) { f('priority').val('2') }
  if (unset(c.aggressiveness)) { f('aggressiveness').val('medium') }
  if (unset(c.eqType)) { $('#sel_emType').val('simple') }
  var isManager = (c.eqType === 'manager')
  $('#sel_emType option').each(function () { var m = ($(this).attr('value') === 'manager'); $(this).prop('disabled', isManager ? !m : m) })
  emShowType()
}

/* Sélecteur de commande des champs de l'équipement */
$('body').off('click', '.emEqCmdPick').on('click', '.emEqCmdPick', function () {
  var input = $(this).closest('.input-group').find('input')
  jeedom.cmd.getSelectModal({ cmd: { type: $(this).data('type') } }, function (result) {
    input.value(result.human)
  })
})

/* Simulateur : page complète (moteur réel, aucun ordre envoyé) */
$('#bt_emSimulator').off('click').on('click', function () {
  window.location.href = 'index.php?v=d&m=energyManager&p=simulator'
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
