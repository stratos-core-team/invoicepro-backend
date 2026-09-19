/**
 * Operational error class. Throw this (not a raw Error) for anything
 * that should surface a specific HTTP status + message to the client,
 * e.g. `throw new AppError(404, 'Customer not found')`.
 */
class AppError extends Error {
  constructor(statusCode, message, details = null) {
    super(message);
    this.statusCode = statusCode;
    this.isOperational = true;
    this.details = details;
    Error.captureStackTrace(this, this.constructor);
  }

  static badRequest(message, details) {
    return new AppError(400, message, details);
  }

  static unauthorized(message = 'Unauthorized') {
    return new AppError(401, message);
  }

  static forbidden(message = 'Forbidden') {
    return new AppError(403, message);
  }

  static notFound(message = 'Resource not found') {
    return new AppError(404, message);
  }

  static conflict(message = 'Conflict') {
    return new AppError(409, message);
  }

  static internal(message = 'Internal server error') {
    return new AppError(500, message);
  }
}

module.exports = AppError;
