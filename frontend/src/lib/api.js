
import axios from 'axios';

export const API_BASE =
  import.meta.env.VITE_API_BASE_URL || '/api';

export const api = axios.create({
  baseURL: API_BASE,
  withCredentials: true,
  timeout: 18000,
  headers: {
    Accept: 'application/json'
  }
});

let csrf = '';

export const updateCsrf = (value) => {
  csrf = typeof value === 'string' ? value : '';
};

const unwrapResponse = (response) => {
  const body = response?.data;

  if (
    !body ||
    typeof body !== 'object' ||
    Array.isArray(body) ||
    body.success !== true ||
    !Object.prototype.hasOwnProperty.call(body, 'data')
  ) {
    throw new Error(
      'Invalid backend response. Check your PHP API and Apache configuration.'
    );
  }

  return body.data;
};

export const ensureCsrf = async () => {
  if (csrf) {
    return csrf;
  }

  try {
    const response = await api.get('/auth/csrf');
    const data = unwrapResponse(response);

    if (
      !data ||
      typeof data.csrf !== 'string' ||
      !data.csrf
    ) {
      throw new Error(
        'CSRF token missing from backend response.'
      );
    }

    updateCsrf(data.csrf);
    return csrf;

  } catch (error) {
    updateCsrf('');
    throw error;
  }
};

api.interceptors.request.use(
  async (config) => {
    const method = (
      config.method || 'get'
    ).toLowerCase();

    const safeMethods = [
      'get',
      'head',
      'options'
    ];

    if (!safeMethods.includes(method)) {
      const token = await ensureCsrf();

      config.headers = config.headers || {};
      config.headers['X-CSRF-Token'] = token;
    }

    return config;
  },
  (error) => Promise.reject(error)
);

api.interceptors.response.use(
  (response) => response,
  (error) => {
    if (error?.response?.status === 419) {
      updateCsrf('');
    }

    return Promise.reject(error);
  }
);

export const getError = (error) => {
  const backendMessage =
    error?.response?.data?.message;

  if (typeof backendMessage === 'string' &&
      backendMessage.trim()) {
    return backendMessage;
  }

  if (error?.code === 'ECONNABORTED') {
    return 'Request timed out. Check Apache and your connection.';
  }

  if (
    error?.code === 'ERR_NETWORK' ||
    (!error?.response && error?.isAxiosError)
  ) {
    return 'Cannot reach the backend. Confirm Apache is running and check your API URL.';
  }

  if (
    typeof error?.response?.data === 'string'
  ) {
    return 'The backend returned HTML instead of JSON. Check PHP errors.';
  }

  if (error?.message) {
    return error.message;
  }

  return 'Something went wrong. Please try again.';
};

export const request = async (
  method,
  url,
  data = undefined
) => {
  const response = await api({
    method,
    url,
    data
  });

  return unwrapResponse(response);
};

export { unwrapResponse };
