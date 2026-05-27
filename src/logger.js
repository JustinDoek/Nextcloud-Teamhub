/**
 * SPDX-FileCopyrightText: TeamHub contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 *
 * Shared application logger.
 *
 * One logger instance for the whole frontend, built once and imported
 * wherever logging is needed. Routes through @nextcloud/logger so output
 * respects the server-configured `loglevel_frontend` and carries the app
 * name and current user id automatically.
 *
 * Usage:
 *   import logger from '../logger.js'
 *   logger.error('Something failed', { error: e })
 *   logger.warn('Unexpected state', { context })
 *   logger.debug('Trace value', { value })
 *
 * Always pass structured context as the second argument — never concatenate
 * error objects into the message string. Never log user content, tokens, or
 * personal data (see SKILLS.md security standards).
 */
import { getLoggerBuilder } from '@nextcloud/logger'

const logger = getLoggerBuilder()
	.setApp('teamhub')
	.detectUser()
	.build()

export default logger
