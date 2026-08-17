(function ($) {
  'use strict';

  var cfg = window.SutoreMarketplace || {};

  function t(key, fallback) {
    return cfg.t ? cfg.t(key, fallback) : fallback;
  }

  function loadingHtml() {
    return (
      '<div class="sutore-mp-list-loading" role="status">' +
      '<span class="sutore-mp-list-spinner" aria-hidden="true"></span>' +
      '<span class="screen-reader-text">' +
      $('<div/>').text(t('loading', 'Loading…')).html() +
      '</span></div>'
    );
  }

  function taskStatusLabel(status) {
    var map = {
      not_started: t('taskNotStarted', 'Not started'),
      in_progress: t('taskInProgress', 'In progress'),
      completed: t('taskCompleted', 'Completed')
    };
    return map[status] || status;
  }

  function rewardLabel(card) {
    if (card.reward_type === 'commission_percent' && card.reward_value > 0) {
      return t('rewardCommission', 'Commission discount') + ': ' + card.reward_value + '%';
    }
    if (card.card_family === 'recovery') {
      return t('rewardScoreRecovery', 'Reward: score recovery');
    }
    return t('rewardEngagement', 'Reward: marketplace engagement');
  }

  function loadTasks($root) {
    if (!$root.length || !cfg.api) {
      return;
    }

    var $tasks = $root.find('.sutore-mp-tasks-results').attr('aria-busy', 'true').html(loadingHtml());

    cfg.api('marketplace_tasks_dashboard', {}).done(function (res) {
      $tasks.empty().attr('aria-busy', 'false');
      if (!res.success) {
        $tasks.text((res.data && res.data.message) || t('error', 'Error'));
        return;
      }

      var cards = res.data.cards || [];
      if (!cards.length) {
        $tasks.append($('<p class="sutore-mp-empty"/>').text(t('opportunitiesEmpty', 'No opportunity cards this month yet.')));
        return;
      }

      var currentFamily = '';
      cards.forEach(function (card) {
        if (card.card_family && card.card_family !== currentFamily) {
          currentFamily = card.card_family;
          $tasks.append(
            $('<h3 class="sutore-mp-section-title"/>').text(card.card_family_label || currentFamily)
          );
        }

        var $card = $('<div class="sutore-mp-task-card"/>');
        if (card.status === 'completed') {
          $card.addClass('is-completed');
        }
        $card.append($('<div class="sutore-mp-task-title"/>').text(card.title || card.task_key));
        $card.append($('<div class="sutore-mp-task-meta"/>').text(
          taskStatusLabel(card.status) + ' · ' + card.progress_count + ' / ' + card.target_count
        ));
        if (card.description) {
          $card.append($('<div class="sutore-mp-task-desc"/>').text(card.description));
        }
        $card.append(
          $('<div class="sutore-mp-progress"/>').append(
            $('<div class="sutore-mp-progress-fill"/>').css('width', (card.percent || 0) + '%')
          )
        );
        $card.append($('<div class="sutore-mp-task-reward"/>').text(rewardLabel(card)));
        $tasks.append($card);
      });
    }).fail(function () {
      $tasks.empty().attr('aria-busy', 'false').text(t('error', 'Error'));
    });
  }

  $(function () {
    loadTasks($('.sutore-mp-tasks-page'));
  });
})(jQuery);
