# Film Production Manager

Console application to manage a film studio's movies, staff and production tasks, built with C++ and object-oriented design.

## Description

System for a film studio that registers its movies and production staff (artists, sound engineers and producers), assigns staff to movies, and tracks the production tasks each person has to complete. Each type of staff member completes tasks in its own way through the Strategy pattern, and all the information can be saved to and loaded from text files.

## Features

- Staff registration with three roles: Artist, Sound Engineer and Producer, each with its own attributes
- Movie registration and assignment of staff to each movie
- Production tasks assigned to staff members and marked as completed
- Different task-completion behavior per role (Strategy pattern)
- Listing of all staff and all movies with their assigned team
- Data persistence: save and load movies, staff, tasks and assignments from text files
- Custom exception hierarchy for invalid input, duplicate IDs and missing records
- Interactive console menu

## Design and Concepts

| Concept | Where |
|---|---|
| Inheritance and polymorphism | `Personal` → `Artista`, `IngenieroSonido`, `Productor` |
| Strategy | `EstrategiaTrabajo` → `EstrategiaArtista`, `EstrategiaSonido`, `EstrategiaProductor` |
| Templates (generic linked list) | `Nodo<T>` and `GestorRecursos<T>`, used by the movie and staff lists |
| Custom exceptions | `ExcepcionStudio` and its subclasses |
| File persistence | `PersistenciaDeDatos` with `Peliculas.txt`, `Personal.txt`, `Tareas.txt` and `PersonalPorPelicula.txt` |

## Tech Stack

- **Language:** C++
- **Concepts:** OOP, design patterns, templates, exceptions, file I/O
- **IDE:** Visual Studio 2022

## How to Run

### Prerequisites

- Windows with Visual Studio 2022 and the "Desktop development with C++" workload

### Steps

1. Clone the repository.
2. Open `TrabajoClasePeliculas.sln` in Visual Studio.
3. Press **Ctrl+F5** (Start Without Debugging).
4. Use option **9** in the menu to load the sample data included in the `.txt` files, or start adding staff and movies from scratch.

## Team

Built with [Alejandro Calderón](https://github.com/AleCalderon1711).
