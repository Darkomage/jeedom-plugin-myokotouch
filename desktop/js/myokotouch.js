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

/* Synchronisation des équipements depuis la chaudière Okofen */
$('#bt_syncEquipments').off('click').on('click', function () {
  $('#div_alert').showAlert({
    message: '{{Synchronisation en cours…}}',
    level: 'warning'
  });
  $.ajax({
    type: 'POST',
    url: 'plugins/myokotouch/core/ajax/myokotouch.ajax.php',
    data: { action: 'syncEquipments' },
    dataType: 'json',
    error: function (request, status, error) {
      handleAjaxError(request, status, error);
    },
    success: function (data) {
      if (data.state !== 'ok') {
        $('#div_alert').showAlert({ message: data.result, level: 'danger' });
        return;
      }
      var result = data.result;
      // Démon actif : la lecture est mise en file (respecte le délai chaudière) et la
      // synchro s'effectue via le callback du démon quelques secondes plus tard.
      if (result && result.queued) {
        $('#div_alert').showAlert({
          message: '{{Synchronisation mise en file du démon — mise à jour dans quelques secondes…}}',
          level: 'info'
        });
        setTimeout(function () { window.location.reload(); }, 6000);
        return;
      }
      // Démon arrêté : synchro directe, résultat immédiat.
      var msg = '{{Synchronisation terminée}} — '
        + result.created + ' {{créé(s)}}, '
        + result.updated + ' {{mis à jour}}';
      $('#div_alert').showAlert({ message: msg, level: 'success' });
      setTimeout(function () { window.location.reload(); }, 1500);
    }
  });
});

/* Zones enroulables avec mémorisation localStorage */
(function () {
  var STORAGE_KEY = 'myokotouch_zone_state';

  function getStates() {
    try { return JSON.parse(localStorage.getItem(STORAGE_KEY) || '{}'); }
    catch (e) { return {}; }
  }

  function saveState(zone, collapsed) {
    var s = getStates();
    s[zone] = collapsed;
    localStorage.setItem(STORAGE_KEY, JSON.stringify(s));
  }

  function initZones() {
    var states = getStates();
    $('.eqLogicThumbnailDisplay legend.oko-zone-toggle').each(function () {
      var $legend = $(this);
      var zone = $legend.data('zone');
      var $content = $('.oko-zone-content[data-zone="' + zone + '"]');
      var $chevron = $legend.find('.oko-zone-chevron');

      if (states[zone] === true) {
        $content.addClass('oko-hidden');
        $chevron.addClass('oko-collapsed');
      }

      $legend.off('click.okozone').on('click.okozone', function () {
        if ($content.hasClass('oko-hidden')) {
          $content.removeClass('oko-hidden');
          $chevron.removeClass('oko-collapsed');
          saveState(zone, false);
        } else {
          $content.addClass('oko-hidden');
          $chevron.addClass('oko-collapsed');
          saveState(zone, true);
        }
      });
    });
  }

  $(document).ready(function () { initZones(); });
})();

/* Avertissement visuel quand l'utilisateur sélectionne le mode expert.
 * On distingue changement utilisateur (mousedown/keydown) vs changement
 * programmatique (setValues de Jeedom via .val().trigger('change')) :
 * mousedown/keydown ne se déclenchent pas lors d'un appel programmatique. */
var _userChangingCmdMode = false;

$(document).on('mousedown keydown', '.eqLogicAttr[data-l2key="cmd_mode"]', function () {
  _userChangingCmdMode = true;
});

$(document).on('change', '.eqLogicAttr[data-l2key="cmd_mode"]', function () {
  if (!_userChangingCmdMode) return;
  _userChangingCmdMode = false;
  var isExpert = $(this).val() === 'expert';
  $('#cmd_mode_expert_warning').toggle(isExpert);
});

/* Détection de la transition standard→expert au moment de la sauvegarde.
 * Si le warning est visible (= l'utilisateur vient de passer en mode expert),
 * on planifie un rechargement pour laisser le temps au démon de régénérer. */
$(document).on('click', '.eqLogicAction[data-action="save"]', function () {
  if ($('#cmd_mode_expert_warning').is(':visible')) {
    $('#cmd_mode_expert_warning').hide();
    setTimeout(function () {
      $('#div_alert').showAlert({
        message: '{{Mode expert activé — régénération des commandes en cours…}}',
        level: 'info'
      });
    }, 600);
    setTimeout(function () { window.location.reload(); }, 6000);
  }
});

/* Bouton Régénérer les commandes — modal 3 boutons */
$('#bt_regenerateCommands').off('click').on('click', function () {
  bootbox.dialog({
    title: '{{Régénérer les commandes}}',
    message: '{{Toutes les commandes seront supprimées et recréées.<br>' +
             '<strong>Les noms que vous avez personnalisés seront écrasés.</strong>}}',
    buttons: {
      cancel: {
        label: '{{Annuler}}',
        className: 'btn-default'
      },
      raw: {
        label: '<i class="fas fa-code"></i> {{Noms bruts (L_xxx)}}',
        className: 'btn-warning',
        callback: function () { doRegenerate('raw'); }
      },
      default: {
        label: '<i class="fas fa-redo"></i> {{Noms par défaut}}',
        className: 'btn-primary',
        callback: function () { doRegenerate('default'); }
      }
    }
  });
});

function doRegenerate(nameMode, cmdMode) {
  var eqLogicId = $('.eqLogicAttr[data-l1key=id]').value() || 0;
  var payload = { action: 'regenerateCommands', eqLogicId: eqLogicId, nameMode: nameMode };
  if (cmdMode) { payload.cmdMode = cmdMode; }
  $.ajax({
    type: 'POST',
    url: 'plugins/myokotouch/core/ajax/myokotouch.ajax.php',
    data: payload,
    dataType: 'json',
    error: function (request, status, error) { handleAjaxError(request, status, error); },
    success: function (data) {
      if (data.state !== 'ok') {
        $('#div_alert').showAlert({ message: data.result, level: 'danger' });
        return;
      }
      $('#div_alert').showAlert({ message: '{{Régénération en cours — mise à jour dans quelques secondes…}}', level: 'info' });
      setTimeout(function () { window.location.reload(); }, 5000);
    }
  });
}

/* Permet la réorganisation des commandes dans l'équipement */
$('#table_cmd').sortable({
  axis: 'y',
  cursor: 'move',
  items: '.cmd',
  placeholder: 'ui-state-highlight',
  tolerance: 'intersect',
  forcePlaceholderSize: true
});

/* Fonction permettant l'affichage des commandes dans l'équipement */
function addCmdToTable(_cmd) {
  if (!isset(_cmd)) {
    var _cmd = { configuration: {} };
  }
  if (!isset(_cmd.configuration)) {
    _cmd.configuration = {};
  }
  var tr = '<tr class="cmd" data-cmd_id="' + init(_cmd.id) + '">';
  tr += '<td class="hidden-xs">';
  tr += '<span class="cmdAttr" data-l1key="id"></span>';
  tr += '</td>';
  tr += '<td>';
  tr += '<div class="input-group">';
  tr += '<input class="cmdAttr form-control input-sm roundedLeft" data-l1key="name" placeholder="{{Nom de la commande}}">';
  tr += '<span class="input-group-btn"><a class="cmdAction btn btn-sm btn-default" data-l1key="chooseIcon" title="{{Choisir une icône}}"><i class="fas fa-icons"></i></a></span>';
  tr += '<span class="cmdAttr input-group-addon roundedRight" data-l1key="display" data-l2key="icon" style="font-size:19px;padding:0 5px 0 0!important;"></span>';
  tr += '</div>';
  tr += '<select class="cmdAttr form-control input-sm" data-l1key="value" style="display:none;margin-top:5px;" title="{{Commande info liée}}">';
  tr += '<option value="">{{Aucune}}</option>';
  tr += '</select>';
  tr += '</td>';
  tr += '<td>';
  tr += '<span class="type" type="' + init(_cmd.type) + '">' + jeedom.cmd.availableType() + '</span>';
  tr += '<span class="subType" subType="' + init(_cmd.subType) + '"></span>';
  tr += '</td>';
  tr += '<td>';
  tr += '<label class="checkbox-inline"><input type="checkbox" class="cmdAttr" data-l1key="isVisible" checked/>{{Afficher}}</label> ';
  tr += '<label class="checkbox-inline"><input type="checkbox" class="cmdAttr" data-l1key="isHistorized" checked/>{{Historiser}}</label> ';
  tr += '<label class="checkbox-inline"><input type="checkbox" class="cmdAttr" data-l1key="display" data-l2key="invertBinary"/>{{Inverser}}</label> ';
  tr += '<div style="margin-top:7px;">';
  tr += '<input class="tooltips cmdAttr form-control input-sm" data-l1key="configuration" data-l2key="minValue" placeholder="{{Min}}" title="{{Min}}" style="width:30%;max-width:80px;display:inline-block;margin-right:2px;">';
  tr += '<input class="tooltips cmdAttr form-control input-sm" data-l1key="configuration" data-l2key="maxValue" placeholder="{{Max}}" title="{{Max}}" style="width:30%;max-width:80px;display:inline-block;margin-right:2px;">';
  tr += '<input class="tooltips cmdAttr form-control input-sm" data-l1key="unite" placeholder="{{Unité}}" title="{{Unité}}" style="width:30%;max-width:80px;display:inline-block;margin-right:2px;">';
  tr += '</div>';
  tr += '</td>';
  tr += '<td>';
  tr += '<span class="cmdAttr" data-l1key="htmlstate"></span>';
  tr += '</td>';
  tr += '<td>';
  if (is_numeric(_cmd.id)) {
    tr += '<a class="btn btn-default btn-xs cmdAction" data-action="configure"><i class="fas fa-cogs"></i></a> ';
    tr += '<a class="btn btn-default btn-xs cmdAction" data-action="test"><i class="fas fa-rss"></i> {{Tester}}</a>';
  }
  tr += '<i class="fas fa-minus-circle pull-right cmdAction cursor" data-action="remove" title="{{Supprimer la commande}}"></i></td>';
  tr += '</tr>';
  $('#table_cmd tbody').append(tr);
  var tr = $('#table_cmd tbody tr').last();
  jeedom.eqLogic.buildSelectCmd({
    id: $('.eqLogicAttr[data-l1key=id]').value(),
    filter: { type: 'info' },
    error: function (error) {
      $('#div_alert').showAlert({ message: error.message, level: 'danger' });
    },
    success: function (result) {
      tr.find('.cmdAttr[data-l1key=value]').append(result);
      tr.setValues(_cmd, '.cmdAttr');
      jeedom.cmd.changeType(tr, init(_cmd.subType));
    }
  });
}
