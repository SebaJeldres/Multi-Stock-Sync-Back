## 1. User endpoints

Endpoints dedicated to authentication and account creation for working in the system.

### Authentication
- **Login**
    - **URL:** `/login`
    - **Method:** `POST`
    - **Parameters:**
        - `email` (string, required)
        - `password` (string, required)
    - **Request Example:**
    ```json
    {
        "email": "user@example.com",
        "password": "yourpassword"
    }
    ```
    - **Response Example:**
    ```json
    {
    "user": {
        "id": 2,
        "nombre": "Astronaut",
        "apellidos": "Markus",
        "telefono": "+9999999999",
        "email": "email@gmail.com",
        "nombre_negocio": "Astronaut Markus Negocio",
        "created_at": "2025-01-02T13:14:37.000000Z",
        "updated_at": "2025-01-02T13:14:37.000000Z"
    },
    "token": "TOKEN_CODE"
    }
    ```
    - **Response Example (Incorrect password):**
    ```json
    {
        "message": "Credenciales inválidas"
    }
    ```
    - **Response Example (Incorrect email or user not exists):**
    ```json
    {
        "message": "Usuario no encontrado"
    }
    ```


- **Register**
    - **URL:** `/register`
    - **Method:** `POST`
    - **Parameters:**
        - `nombre` (string, required)
        - `apellidos` (string, required)
        - `telefono` (string, required)
        - `email` (string, required)
        - `nombre_negocio` (string, required)
        - `password` (string, required)
        - `password_confirmation` (string, required)
    - **Request Example:**
    ```json
    {
        "nombre":"Astronaut",
        "apellidos":"Markus",
        "telefono":"+99999999999",
        "email":"email@gmail.com",
        "nombre_negocio":"Astronaut Markus Negocio",
        "password":"password_here",
        "password_confirmation":"password_here"
    }
    ```
    - **Response Example:**
    ```json
    {
        "user": {
            "nombre": "Astronaut",
            "apellidos": "Markus",
            "telefono": "+99999999999",
            "email": "email@gmail.com",
            "nombre_negocio": "Astronaut Markus Negocio",
            "updated_at": "2025-01-02T13:26:52.000000Z",
            "created_at": "2025-01-02T13:26:52.000000Z",
            "id": 3
        },
        "message": "Usuario creado correctamente"
    }
    ```

- **Logout**
    - **URL:** `/logout`
    - **Method:** `POST`
    - **Headers:**
        - `Authorization: Bearer {token}`
