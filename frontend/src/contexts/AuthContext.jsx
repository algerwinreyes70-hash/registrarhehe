
import {
  createContext,
  useCallback,
  useContext,
  useEffect,
  useState
} from 'react';

import {
  api,
  updateCsrf,
  getError,
  unwrapResponse
} from '../lib/api';

const Context = createContext(null);

export function AuthProvider({ children }) {
  const [user, setUser] = useState(null);
  const [loading, setLoading] = useState(true);
  const [startupError, setStartupError] = useState('');

  const refresh = useCallback(async () => {
    try {
      const response = await api.get('/auth/me');
      const data = unwrapResponse(response);

      if (
        !data ||
        !Object.prototype.hasOwnProperty.call(data, 'user')
      ) {
        throw new Error(
          'Invalid user data returned by backend.'
        );
      }

      const currentUser = data.user || null;

      setUser(currentUser);
      setStartupError('');

      return currentUser;

    } catch (error) {
      setUser(null);

      if (error?.response?.status === 401) {
        setStartupError('');
      } else {
        setStartupError(getError(error));
      }

      return null;
    }
  }, []);

  useEffect(() => {
    let mounted = true;

    const initialize = async () => {
      await refresh();

      if (mounted) {
        setLoading(false);
      }
    };

    initialize();

    return () => {
      mounted = false;
    };
  }, [refresh]);

  const login = async (identity, password) => {
    const response = await api.post('/auth/login', {
      identity,
      password
    });

    const data = unwrapResponse(response);

    if (!data || !data.user) {
      throw new Error(
        'Login failed. Invalid user response from backend.'
      );
    }

    updateCsrf(data.csrf);
    setUser(data.user);
    setStartupError('');

    return data.user;
  };

  const register = async (formData) => {
    const response = await api.post(
      '/auth/register-student',
      formData
    );

    const data = unwrapResponse(response);

    if (!data || !data.user) {
      throw new Error(
        'Registration failed. Invalid backend response.'
      );
    }

    updateCsrf(data.csrf);
    setUser(data.user);
    setStartupError('');

    return data.user;
  };

  const logout = async () => {
    try {
      await api.post('/auth/logout');

    } finally {
      updateCsrf('');
      setUser(null);
      setStartupError('');
    }
  };

  return (
    <Context.Provider
      value={{
        user,
        loading,
        startupError,
        login,
        register,
        logout,
        refresh
      }}
    >
      {children}
    </Context.Provider>
  );
}

export function useAuth() {
  const value = useContext(Context);

  if (!value) {
    throw new Error(
      'useAuth needs AuthProvider'
    );
  }

  return value;
}
