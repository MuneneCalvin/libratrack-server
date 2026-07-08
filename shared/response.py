import json
from rest_framework.renderers import JSONRenderer


class EnvelopeRenderer(JSONRenderer):
    def render(self, data, accepted_media_type=None, renderer_context=None):
        if renderer_context is None:
            return super().render(data, accepted_media_type, renderer_context)

        response = renderer_context.get('response')
        if response is None:
            return super().render(data, accepted_media_type, renderer_context)

        status_code = response.status_code

        if status_code >= 400:
            if isinstance(data, dict):
                message = data.get('detail', data.get('message', str(data)))
                if not isinstance(message, str):
                    message = str(message)
                extra_fields = {
                    key: value
                    for key, value in data.items()
                    if key not in ('detail', 'message')
                }
            else:
                message = str(data) if data else 'An error occurred'
                extra_fields = {}
            envelope = {'status': 'error', 'message': message}
            envelope.update(extra_fields)
        elif isinstance(data, dict) and 'results' in data:
            envelope = {
                'status': 'success',
                'data': data['results'],
                'meta': {
                    'total': data.get('count', 0),
                    'page': data.get('page', 1),
                    'limit': data.get('limit', 10),
                    'totalPages': data.get('total_pages', 1),
                },
            }
        elif isinstance(data, dict) and 'data' in data and 'meta' in data and isinstance(data.get('data'), list):
            # StandardPagination already produced {data: [...], meta: {...}} — unwrap one level
            envelope = {'status': 'success', 'data': data['data'], 'meta': data['meta']}
        else:
            envelope = {'status': 'success', 'data': data}

        return json.dumps(envelope, default=str).encode()


def exception_handler(exc, context):
    # Import lazily to avoid circular import (rest_framework.views imports
    # DEFAULT_RENDERER_CLASSES from settings which imports this module).
    from rest_framework.views import exception_handler as drf_exception_handler
    from rest_framework.exceptions import AuthenticationFailed, NotAuthenticated
    response = drf_exception_handler(exc, context)
    # DRF downgrades 401 → 403 when authentication_classes = []. Restore the
    # correct 401 status for all authentication-related exceptions.
    if isinstance(exc, (AuthenticationFailed, NotAuthenticated)) and response is not None:
        response.status_code = 401
    return response
