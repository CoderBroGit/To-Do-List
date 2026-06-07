-- ============================================================
-- FocusTrack — database.sql (v2)
-- Includes: users, tasks, daily_activity, streaks, pomodoro_sessions
-- ============================================================

CREATE DATABASE IF NOT EXISTS focustrack
  CHARACTER SET utf8mb4
  COLLATE utf8mb4_unicode_ci;

USE focustrack;

-- ------------------------------------------------------------
-- users
-- ------------------------------------------------------------
CREATE TABLE IF NOT EXISTS users (
  id          INT UNSIGNED    NOT NULL AUTO_INCREMENT,
  name        VARCHAR(100)    NOT NULL,
  email       VARCHAR(191)    NOT NULL,
  password    VARCHAR(255)    NOT NULL,
  created_at  TIMESTAMP       NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (id),
  UNIQUE KEY uq_email (email)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- ------------------------------------------------------------
-- tasks
-- ------------------------------------------------------------
CREATE TABLE IF NOT EXISTS tasks (
  id          INT UNSIGNED    NOT NULL AUTO_INCREMENT,
  user_id     INT UNSIGNED    NOT NULL,
  title       VARCHAR(255)    NOT NULL,
  is_done     TINYINT(1)      NOT NULL DEFAULT 0,
  created_at  TIMESTAMP       NOT NULL DEFAULT CURRENT_TIMESTAMP,
  completed_at TIMESTAMP      NULL     DEFAULT NULL,
  updated_at  TIMESTAMP       NOT NULL DEFAULT CURRENT_TIMESTAMP
                              ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (id),
  KEY idx_user_id (user_id),
  KEY idx_completed (completed_at),
  CONSTRAINT fk_tasks_user
    FOREIGN KEY (user_id) REFERENCES users (id)
    ON DELETE CASCADE ON UPDATE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- ------------------------------------------------------------
-- daily_activity
-- One row per user per calendar day.
-- tasks_completed increments each time a task is marked done.
-- Used to compute streaks and the 10/30-day productivity charts.
-- ------------------------------------------------------------
CREATE TABLE IF NOT EXISTS daily_activity (
  id               INT UNSIGNED NOT NULL AUTO_INCREMENT,
  user_id          INT UNSIGNED NOT NULL,
  activity_date    DATE         NOT NULL,
  tasks_completed  INT UNSIGNED NOT NULL DEFAULT 0,
  tasks_added      INT UNSIGNED NOT NULL DEFAULT 0,
  pomodoro_minutes INT UNSIGNED NOT NULL DEFAULT 0,
  PRIMARY KEY (id),
  UNIQUE KEY uq_user_date (user_id, activity_date),
  CONSTRAINT fk_activity_user
    FOREIGN KEY (user_id) REFERENCES users (id)
    ON DELETE CASCADE ON UPDATE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- ------------------------------------------------------------
-- streaks
-- One row per user. Updated on every task-complete event.
-- ------------------------------------------------------------
CREATE TABLE IF NOT EXISTS streaks (
  user_id        INT UNSIGNED NOT NULL,
  current_streak INT UNSIGNED NOT NULL DEFAULT 0,
  longest_streak INT UNSIGNED NOT NULL DEFAULT 0,
  last_active    DATE         NULL     DEFAULT NULL,
  PRIMARY KEY (user_id),
  CONSTRAINT fk_streak_user
    FOREIGN KEY (user_id) REFERENCES users (id)
    ON DELETE CASCADE ON UPDATE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- ------------------------------------------------------------
-- pomodoro_sessions
-- One row per completed (or abandoned) Pomodoro session.
-- duration_minutes: the target set by the user (>= 20).
-- completed: 1 if the timer ran to zero, 0 if stopped early.
-- ------------------------------------------------------------
CREATE TABLE IF NOT EXISTS pomodoro_sessions (
  id               INT UNSIGNED NOT NULL AUTO_INCREMENT,
  user_id          INT UNSIGNED NOT NULL,
  task_id          INT UNSIGNED NULL     DEFAULT NULL,
  task_title       VARCHAR(255) NOT NULL DEFAULT '',
  duration_minutes INT UNSIGNED NOT NULL DEFAULT 25,
  started_at       TIMESTAMP    NOT NULL DEFAULT CURRENT_TIMESTAMP,
  ended_at         TIMESTAMP    NULL     DEFAULT NULL,
  completed        TINYINT(1)   NOT NULL DEFAULT 0,
  PRIMARY KEY (id),
  KEY idx_pomo_user (user_id),
  KEY idx_pomo_task (task_id),
  CONSTRAINT fk_pomo_user
    FOREIGN KEY (user_id) REFERENCES users (id)
    ON DELETE CASCADE ON UPDATE CASCADE,
  CONSTRAINT fk_pomo_task
    FOREIGN KEY (task_id) REFERENCES tasks (id)
    ON DELETE SET NULL ON UPDATE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

ALTER TABLE tasks
ADD COLUMN completed_at TIMESTAMP NULL DEFAULT NULL AFTER is_done;
