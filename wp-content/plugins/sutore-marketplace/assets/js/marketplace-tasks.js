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

  function loadTasks($root) {
    if (!$root.length || !cfg.api) {
      return;
    }

    var $tasks = $root.find('.sutore-mp-tasks-results').attr('aria-busy', 'true').html(loadingHtml());
    var $rewards = $root.find('.sutore-mp-rewards-results').attr('aria-busy', 'true').html(loadingHtml());

    cfg.api('marketplace_tasks_dashboard', {}).done(function (res) {
      $tasks.empty().attr('aria-busy', 'false');
      $rewards.empty().attr('aria-busy', 'false');
      if (!res.success) {
        $tasks.text((res.data && res.data.message) || t('error', 'Error'));
        return;
      }

      var tasks = res.data.tasks || [];
      if (!tasks.length) {
        $tasks.append($('<p class="sutore-mp-empty"/>').text(t('tasksEmpty', 'No tasks defined yet.')));
      } else {
        tasks.forEach(function (task) {
          var $card = $('<div class="sutore-mp-task-card"/>');
          if (task.status === 'completed') {
            $card.addClass('is-completed');
          }
          $card.append($('<div class="sutore-mp-task-title"/>').text(task.title || task.task_key));
          $card.append($('<div class="sutore-mp-task-meta"/>').text(
            taskStatusLabel(task.status) + ' · ' + task.progress_count + ' / ' + task.target_count
          ));
          if (task.description) {
            $card.append($('<div class="sutore-mp-task-desc"/>').text(task.description));
          }
          $card.append(
            $('<div class="sutore-mp-progress"/>').append(
              $('<div class="sutore-mp-progress-fill"/>').css('width', (task.percent || 0) + '%')
            )
          );
          $card.append($('<div class="sutore-mp-task-reward"/>').text(
            t('reward', 'Reward') + ': ' + task.reward_type + ' ' + task.reward_value
          ));
          $tasks.append($card);
        });
      }

      var rewards = res.data.rewards || [];
      if (!rewards.length) {
        $rewards.append($('<p class="sutore-mp-empty"/>').text(t('rewardsEmpty', 'No rewards yet.')));
      } else {
        rewards.forEach(function (r) {
          $rewards.append(
            $('<div class="sutore-mp-reward-row"/>').text(
              '#' + r.id + ' · ' + r.reward_type + ' ' + r.reward_value + (r.created_at ? ' · ' + r.created_at : '')
            )
          );
        });
      }
    }).fail(function () {
      $tasks.attr('aria-busy', 'false').text(t('error', 'Error'));
      $rewards.attr('aria-busy', 'false').empty();
    });
  }

  $(function () {
    var $root = $('.sutore-mp-tasks-page .sutore-mp-tasks');
    if ($root.length) {
      loadTasks($root);
    }
  });
}(jQuery));
