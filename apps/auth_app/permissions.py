from rest_framework.permissions import BasePermission


class IsAdmin(BasePermission):
    def has_permission(self, request, view):
        return bool(
            request.user
            and request.user.is_authenticated
            and request.user.role.name == 'admin'
        )


class IsLibrarian(BasePermission):
    def has_permission(self, request, view):
        return bool(
            request.user
            and request.user.is_authenticated
            and request.user.role.name == 'librarian'
        )


class IsAdminOrLibrarian(BasePermission):
    def has_permission(self, request, view):
        return bool(
            request.user
            and request.user.is_authenticated
            and request.user.role.name in ('admin', 'librarian')
        )


class IsSelfOrAdminOrLibrarian(BasePermission):
    def has_permission(self, request, view):
        return bool(request.user and request.user.is_authenticated)

    def has_object_permission(self, request, view, obj):
        if request.user.role.name in ('admin', 'librarian'):
            return True
        try:
            return request.user.member == obj
        except Exception:
            return False
