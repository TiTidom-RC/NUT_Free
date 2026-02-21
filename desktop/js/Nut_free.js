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

;(() => {
'use strict'

// Commandes pouvant être historisées
const HISTORIZED_COMMANDS = Object.freeze([
  'input_volt', 'input_freq', 'output_volt', 'output_freq',
  'output_power', 'output_real_power', 'batt_charge', 'batt_volt',
  'batt_temp', 'ups_temp', 'ups_load', 'batt_runtime', 'batt_runtime_min',
  'timer_shutdown', 'timer_shutdown_min'
])

// Commandes affichables (visibles par défaut)
const VISIBLE_COMMANDS = Object.freeze([
  'Marque', 'Model', 'ups_serial', 'ups_line',
  'input_volt', 'input_freq', 'output_volt', 'output_freq',
  'output_power', 'output_real_power', 'batt_charge', 'batt_volt',
  'batt_temp', 'ups_temp', 'ups_load', 'batt_runtime', 'batt_runtime_min',
  'timer_shutdown', 'timer_shutdown_min', 'beeper_stat'
])

/**
 * Construit la ligne de commande dans le tableau de l'onglet Commandes
 */
function addCmdToTable(_cmd) {
  if (!isset(_cmd)) _cmd = { configuration: {} }
  if (!isset(_cmd.configuration)) _cmd.configuration = {}

  const logicalId     = init(_cmd.logicalId)
  const canBeVisible  = VISIBLE_COMMANDS.includes(logicalId)
  const canHistorize  = HISTORIZED_COMMANDS.includes(logicalId)

  const testButtons = is_numeric(_cmd.id)
    ? `<a class="btn btn-default btn-xs cmdAction" data-action="configure"><i class="fas fa-cogs"></i></a>
       <a class="btn btn-default btn-xs cmdAction" data-action="test"><i class="fas fa-rss"></i> {{Tester}}</a>`
    : ''

  const rowHtml = `
    <td class="hidden-xs"><span class="cmdAttr" data-l1key="id"></span></td>
    <td>
      <div class="input-group">
        <input class="cmdAttr form-control input-sm" data-l1key="type" value="info" style="display:none">
        <input class="cmdAttr form-control input-sm roundedLeft" data-l1key="name" placeholder="{{Nom de la commande}}">
        <span class="cmdAttr input-group-addon roundedRight" data-l1key="display" data-l2key="icon" style="font-size:19px;padding:0 5px 0 5px!important;"></span>
      </div>
    </td>
    <td>
      ${canBeVisible  ? '<label class="checkbox-inline"><input type="checkbox" class="cmdAttr" data-size="mini" data-l1key="isVisible" checked/> {{Afficher}}</label>' : ''}
      ${canHistorize  ? '<label class="checkbox-inline"><input type="checkbox" class="cmdAttr" data-size="mini" data-l1key="isHistorized"/> {{Historiser}}</label>' : ''}
    </td>
    <td>
      ${testButtons}
      <i class="fas fa-minus-circle pull-right cmdAction cursor" data-action="remove" title="{{Supprimer la commande}}"></i>
    </td>`

  const row = Object.assign(document.createElement('tr'), {
    className: 'cmd',
    innerHTML: rowHtml
  })
  row.setAttribute('data-cmd_id', init(_cmd.id))

  const tbody = document.querySelector('#table_cmd tbody')
  if (!tbody) return
  tbody.appendChild(row)
  row.setJeeValues(_cmd, '.cmdAttr')
}

/**
 * Affichage conditionnel des sections local/distant et UPS manuel
 */
function updateLocalDistantDisplay(value) {
  const localEl   = document.querySelector('.nut-local')
  const distantEl = document.querySelector('.nut-distant')
  const isDistant = value === 'distant'
  if (localEl)   localEl.style.display   = isDistant ? 'none' : ''
  if (distantEl) distantEl.style.display = isDistant ? '' : 'none'
}

function updateUpsManualDisplay(value) {
  const manualEl = document.querySelector('.nut-ups-manual')
  if (manualEl) manualEl.style.display = (value === '1') ? '' : 'none'
}

/**
 * Appelé par Jeedom lors du chargement d'un équipement dans le formulaire
 */
function printEqLogic(_eqLogic) {
  if (!_eqLogic) return

  // Affichage local/distant
  const localDistant = _eqLogic.configuration?.localoudistant ?? 'local'
  updateLocalDistantDisplay(localDistant)

  // Affichage nom UPS manuel
  const upsAuto = _eqLogic.configuration?.UPS_auto_select ?? '0'
  updateUpsManualDisplay(upsAuto)

  // Événements dynamiques
  const selLocalDistant = document.querySelector('#sel_localoudistant')
  if (selLocalDistant) {
    selLocalDistant.addEventListener('change', function () {
      updateLocalDistantDisplay(this.value)
    })
  }

  const selUpsAuto = document.querySelector('#sel_ups_auto')
  if (selUpsAuto) {
    selUpsAuto.addEventListener('change', function () {
      updateUpsManualDisplay(this.value)
    })
  }
}

// Event delegation openLocation (un seul listener global)
if (!window._nutFreeOpenLocationAttached) {
  window._nutFreeOpenLocationAttached = true
  document.addEventListener('click', (event) => {
    const target = event.target.closest('.pluginAction[data-action=openLocation]')
    if (target) {
      event.preventDefault()
      window.open(target.getAttribute('data-location'), '_blank', null)
    }
  })
}

})()
