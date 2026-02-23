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

;(function() {
'use strict'

// Commandes pouvant être historisées
const HISTORIZED_COMMANDS = Object.freeze([
  'input_voltage', 'input_freq', 'output_voltage', 'output_freq',
  'output_power', 'output_real_power', 'batt_charge', 'batt_voltage',
  'batt_temp', 'ups_temp', 'ups_load', 'batt_runtime', 'batt_runtime_min',
  'timer_shutdown', 'timer_shutdown_min'
])

// Commandes affichables (visibles par défaut)
const VISIBLE_COMMANDS = Object.freeze([
  'device_mfr', 'device_model', 'ups_serial', 'ups_status', 'ups_status_label',
  'input_voltage', 'input_freq', 'output_voltage', 'output_freq',
  'output_power', 'output_real_power', 'batt_charge', 'batt_voltage',
  'batt_temp', 'ups_temp', 'ups_load', 'batt_runtime', 'batt_runtime_min',
  'timer_shutdown', 'timer_shutdown_min', 'beeper_status',
  'beeper_disable', 'beeper_enable', 'beeper_mute', 'test_battery_quick', 'test_battery_stop',
  'cmd_result'
])

/**
 * Construit la ligne de commande dans le tableau de l'onglet Commandes
 */
function addCmdToTable(_cmd) {
  if (!isset(_cmd)) _cmd = { configuration: {} }
  if (!isset(_cmd.configuration)) _cmd.configuration = {}

  const logicalId       = init(_cmd.logicalId)
  const canBeVisible    = VISIBLE_COMMANDS.includes(logicalId)
  const canBeHistorized = HISTORIZED_COMMANDS.includes(logicalId)

  const testButtons = is_numeric(_cmd.id)
    ? '<a class="btn btn-default btn-xs cmdAction" data-action="configure"><i class="fas fa-cogs"></i></a> <a class="btn btn-default btn-xs cmdAction" data-action="test"><i class="fas fa-rss"></i> {{Tester}}</a>'
    : ''

  const rowHtml = `<td class="hidden-xs"><span class="cmdAttr" data-l1key="id"></span></td>
    <td>
      <div class="input-group">
        <input class="cmdAttr form-control input-sm" data-l1key="type" value="info" style="display:none">
        <input class="cmdAttr form-control input-sm roundedLeft" data-l1key="name" placeholder="{{Nom de la commande}}">
        <span class="input-group-btn"><a class="cmdAction btn btn-sm btn-default" data-l1key="chooseIcon" title="{{Choisir une icône}}"><i class="fas fa-icons"></i></a></span>
        <span class="cmdAttr input-group-addon roundedRight" data-l1key="display" data-l2key="icon" style="font-size:19px;padding:0 5px 0 0!important;"></span>
      </div>
    </td>
    <td>
      <span class="cmdAttr label label-default" data-l1key="configuration" data-l2key="nutCmd" style="font-size:0.9em;display:inline-block;"></span>
    </td>
    <td>
      ${canBeHistorized ? '<input class="cmdAttr form-control input-sm" data-l1key="unite" placeholder="{{Unité}}" style="width:80px;">' : ''}
    </td>
    <td>
      ${canBeVisible    ? '<label class="checkbox-inline"><input type="checkbox" class="cmdAttr" data-l1key="isVisible"/> {{Afficher}}</label>' : ''}
      ${canBeHistorized ? '<label class="checkbox-inline"><input type="checkbox" class="cmdAttr" data-l1key="isHistorized"/> {{Historiser}}</label>' : ''}
    </td>
    <td><span class="cmdAttr" data-l1key="htmlstate"></span></td>
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
  jeedom.cmd.changeType(row, init(_cmd.subType))
}

/**
 * Toggle affichage mot de passe (pattern SSH-Manager)
 */
document.addEventListener('click', function(event) {
  const toggleBtn = event.target.closest('a.bt_togglePass')
  if (!toggleBtn) return
  event.stopPropagation()
  const input = toggleBtn.closest('.input-group').querySelector('input')
  const icon = toggleBtn.querySelector('.fas')
  input.type = input.type === 'password' ? 'text' : 'password'
  icon.classList.toggle('fa-eye')
  icon.classList.toggle('fa-eye-slash')
})

/**
 * Affichage conditionnel des sections NUT/SSH et UPS manuel
 */
function updateConnexionModeDisplay(value) {
  const nutEl  = document.querySelector('.nut-protocol')
  const sshEl  = document.querySelector('.nut-ssh')
  const listEl = document.querySelector('.nut-list-section')
  const isSsh  = value === 'ssh'
  if (nutEl)  nutEl.style.display  = isSsh ? 'none' : ''
  if (sshEl)  sshEl.style.display  = isSsh ? '' : 'none'
  if (listEl) listEl.style.display = isSsh ? 'none' : ''
}

function updateUpsManualDisplay(value) {
  const manualEl = document.querySelector('.nut-ups-manual')
  if (manualEl) manualEl.style.display = (value === '1') ? '' : 'none'
}

/**
 * Appelé par Jeedom lors du chargement d'un équipement dans le formulaire
 * Jeedom remplit les eqLogicAttr AVANT d'appeler cette fonction.
 * Pour les selects, si la valeur est vide (nouvel équipement), on force le défaut.
 */
function printEqLogic(_eqLogic) {
  if (!_eqLogic) return

  // Mode protocole — lire depuis le DOM (déjà rempli par Jeedom), forcer le défaut si vide
  const selConnexionMode = document.querySelector('#selConnexionMode')
  if (selConnexionMode) {
    if (!selConnexionMode.value) selConnexionMode.value = 'nut'
    updateConnexionModeDisplay(selConnexionMode.value)
    selConnexionMode.removeEventListener('change', handleConnexionModeChange)
    selConnexionMode.addEventListener('change', handleConnexionModeChange)
  }

  // Boutons Rafraîchir — fire & message
  const refreshList = (type) => {
    const eqLogicId = _eqLogic.id
    if (!eqLogicId) {
      jeedomUtils.showAlert({ message: '{{Sauvegardez d\'abord l\'équipement avant de lancer une requête}}', level: 'warning' })
      return
    }
    fetch('plugins/Nut_free/core/ajax/Nut_free.ajax.php', {
      method: 'POST',
      headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
      body: new URLSearchParams({ action: 'getNutList', eqLogicId, type })
    })
    .then(r => r.json())
    .then(data => {
      if (data.state === 'ok') {
        jeedomUtils.showAlert({ message: data.result, level: 'info' })
      } else {
        jeedomUtils.showAlert({ message: data.result ?? '{{Erreur inconnue}}', level: 'danger' })
      }
    })
    .catch(err => {
      jeedomUtils.showAlert({ message: String(err), level: 'danger' })
    })
  }

  const btInstcmds = document.querySelector('#bt_refresh_instcmds')
  const btRwvars   = document.querySelector('#bt_refresh_rwvars')
  if (btInstcmds) btInstcmds.onclick = () => refreshList('instcmds')
  if (btRwvars)   btRwvars.onclick   = () => refreshList('rwvars')

  // Auto-détection UPS — lire depuis le DOM, forcer le défaut si vide
  const selUpsAuto = document.querySelector('#selUpsAuto')
  if (selUpsAuto) {
    if (!selUpsAuto.value) selUpsAuto.value = '0'
    updateUpsManualDisplay(selUpsAuto.value)
    selUpsAuto.removeEventListener('change', handleUpsAutoChange)
    selUpsAuto.addEventListener('change', handleUpsAutoChange)
  }

  // Liste des hôtes SSH — peuplée via buildSelectHost (fourni par sshmanager.helper.js)
  if (typeof window.buildSelectHost === 'function') {
    const buildPromise = window.buildSelectHost(_eqLogic.configuration?.SSHHostId)
    const sshHostSelect = document.querySelector('.sshmanagerHelper[data-helper="list"]')
    if (sshHostSelect) {
      sshHostSelect.removeEventListener('change', toggleSSHButtons)
      sshHostSelect.addEventListener('change', toggleSSHButtons)
      if (buildPromise && buildPromise.then) {
        buildPromise.then(() => {
          toggleSSHButtons(_eqLogic.configuration?.SSHHostId)
        })
      } else {
        toggleSSHButtons(_eqLogic.configuration?.SSHHostId)
      }
    }
  }
}

function handleConnexionModeChange() {
  updateConnexionModeDisplay(this.value)
}

function handleUpsAutoChange() {
  updateUpsManualDisplay(this.value)
}

/**
 * Gestion des boutons Ajouter/Éditer selon la sélection de l'hôte SSH
 * @param {Event|string|number} eventOrValue
 */
function toggleSSHButtons(eventOrValue) {
  let selectedValue
  if (typeof eventOrValue === 'string' || typeof eventOrValue === 'number') {
    selectedValue = eventOrValue
  } else if (eventOrValue?.target || eventOrValue?.currentTarget) {
    selectedValue = eventOrValue.target?.value ?? eventOrValue.currentTarget?.value
  }
  if (!selectedValue) {
    selectedValue = document.querySelector('.sshmanagerHelper[data-helper="list"]')?.value
  }
  const addBtn  = document.querySelector('.sshmanagerHelper[data-helper="add"]')
  const editBtn = document.querySelector('.sshmanagerHelper[data-helper="edit"]')
  if (selectedValue && selectedValue !== '') {
    if (addBtn)  addBtn.style.display  = 'none'
    if (editBtn) editBtn.style.display = 'block'
  } else {
    if (addBtn)  addBtn.style.display  = 'block'
    if (editBtn) editBtn.style.display = 'none'
  }
}

// Exposition globale pour Jeedom Core
window.addCmdToTable = addCmdToTable
window.printEqLogic  = printEqLogic

// Event delegation (un seul listener sur document.body, guard anti-doublon SPA)
if (!window._nutFreeClickAttached) {
  window._nutFreeClickAttached = true
  document.body.addEventListener('click', (event) => {
    const openTarget = event.target.closest('.pluginAction[data-action=openLocation]')
    if (openTarget) {
      const location = openTarget.getAttribute('data-location')
      if (location) window.open(location, '_blank', null)
    }

    if (event.target.closest('[data-action="createCommunityPost"]')) {
      jeedom.plugin.createCommunityPost({
        type: eqType,
        error: function (error) {
          jeedomUtils.showAlert({ message: error.message, level: 'danger' })
        },
        success: function (data) {
          const link = document.createElement('a')
          link.href = data.url
          link.target = '_blank'
          link.style.display = 'none'
          document.body.appendChild(link)
          link.click()
          document.body.removeChild(link)
        }
      })
    }
  })
}

})()
