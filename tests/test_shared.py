import json
import pytest
from unittest.mock import MagicMock
from shared.response import EnvelopeRenderer
from shared.pagination import StandardPagination


def make_context(status_code):
    response = MagicMock()
    response.status_code = status_code
    return {'response': response}


def test_envelope_success_object():
    renderer = EnvelopeRenderer()
    result = json.loads(renderer.render({'id': 1, 'name': 'test'}, renderer_context=make_context(200)))
    assert result == {'status': 'success', 'data': {'id': 1, 'name': 'test'}}


def test_envelope_success_paginated():
    renderer = EnvelopeRenderer()
    data = {'results': [{'id': 1}], 'count': 1, 'page': 1, 'limit': 10, 'total_pages': 1}
    result = json.loads(renderer.render(data, renderer_context=make_context(200)))
    assert result['status'] == 'success'
    assert result['data'] == [{'id': 1}]
    assert result['meta'] == {'total': 1, 'page': 1, 'limit': 10, 'totalPages': 1}


def test_envelope_404_error():
    renderer = EnvelopeRenderer()
    result = json.loads(renderer.render({'detail': 'Not found.'}, renderer_context=make_context(404)))
    assert result == {'status': 'error', 'message': 'Not found.'}


def test_envelope_400_error():
    renderer = EnvelopeRenderer()
    result = json.loads(renderer.render({'detail': 'Invalid data.'}, renderer_context=make_context(400)))
    assert result['status'] == 'error'
    assert 'Invalid data' in result['message']


def test_envelope_no_context():
    renderer = EnvelopeRenderer()
    result = renderer.render({'key': 'val'}, renderer_context=None)
    assert b'key' in result
